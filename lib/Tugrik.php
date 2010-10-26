<?php
/**
 * Tugrik
 *
 * Some ORM-style MongoDB database abstraction layer for PHP
 * 
 * As of now, this one is in an unstable experimental pre-alpha state.
 * Take a look, but do not use, even at your own risk. 
 * Everything is subject to change.
 *
 * - Classnames are treated case-sensitive.
 * - Avoid required arguments in class constructors,
 *   for Tugrik can't instantiate a new Object by guessing
 *   the right parameters at the moment
 * - It's possible to store two identical Objects, i.e. having the same hash
 * - Add observers / events
 *
 * @package     Tugrik
 * @subpackage  Libraries
 * @category    Database
 * @author      Axel Tietje <axel@tietje.eu>
 * @link        http://github.net/atietje/Tugrik
 */
class Tugrik
{
    /**
     * Singleton instance of Tugrik
     *
     * @var object
     **/
    private static $_instance = null;

    /**
     * DSN
     *
     * @var string
     */
    private static $_dsn = '';

    /**
     * Database name
     *
     * @var string
     */
    private static $_database = '';

    /**
     * Instance of Mongo
     *
     * @var object
     */
    private $_mongo = null;

    /**
     * Instance of MongoDB
     *
     * @var object
     */
    private $_db = null;
    
    /**
     * Keeps track of recursions
     *
     * @var array
     **/
    private $_recursions = array();

    /**
     * Keeps track of objects
     * 
     * @todo Check if thos should become an SplObjectStorage
     * @var array
     **/
    private $_objects = array();

    /**
     * Constructor
     * 
     * Making the constructor private makes it impossible
     * to circumvent Tugrik::setup() and Tugrik::singleton()
     * to 
     *
     * @author Axel Tietje
     */
    private function __construct()
    {
        if ('' === self::$_dsn ||
            '' === self::$_database
        ) {
            throw new Exception('Call Tugrik::setup() to set database and dsn');
            return;
        }
        
        try {
            $this->_mongo = new Mongo(self::$_dsn);
        } catch (MongoConnectionException $e) {
            throw $e;
            return;
        }
        
        $this->_db = $this->_mongo->{self::$_database};
    }

    /**
     * Configure Tugrik
     *
     * @return boolean
     * @author Axel Tietje
     * @todo Sanitize $database
     * @param string $database Name of the database to be used
     * @param string $dsn The Data Source Name, or DSN, contains the information required to connect to the database.
     **/
    public static function setup($database, $dsn='mongodb://localhost:27017')
    {
        self::$_database = $database;
        self::$_dsn = $dsn;
        return true;
    }

    /**
     * Returns a singleton instance of Tugrik
     *
     * @return object
     * @author Axel Tietje
     */
    public static function singleton() 
    {
        if (!isset(self::$_instance)) {
            $class = __CLASS__;
            self::$_instance = new $class;
        }
        return self::$_instance;
    }
    
    /**
     * Prevent users to clone the instance
     *
     * @return void
     * @author Axel Tietje
     */
    public function __clone()
    {
        trigger_error('Cloning Tugrik is not allowed.', E_USER_ERROR);
    }

    /**
     * Upsert a PHP Object
     *
     * @param object $obj Object to be stored. Do not use stdClass, it has no reflectable properties.
     * @return string Object identifier on success, false on error
     * @author Axel Tietje
     */
    public function store($obj)
    {
        if (false === is_object($obj)) {
            throw new InvalidArgumentException('Tugrik::store() only accepts objects');
            return false;
        }

        $this->_recursions = array();

        $rv = $this->_reflect($obj);

        // @todo: Handle errors
        $this->_objects[$obj->_oid] = $obj;

        return $rv;
    }
    
    /**
     * Fetch a previously stored PHP object 
     *
     * @param mixed $arg Object-ID or array with a key '_oid'
     * @return object Rebuilt object or false on error
     * @author Axel Tietje
     */
    function fetch($arg)
    {
        if (is_array($arg) &&
            isset($arg['_oid'])
        ) {
            return $this->fetch($arg['_oid']);
        }

        if (!is_string($arg)) {
            throw new InvalidArgumentException('Tugrik::fetch() only accepts strings');
            return false;
        }

        // Already fetched?
        if (isset($this->_objects[$arg])) {
            return $this->_objects[$arg];
        }

        list($cls, $oid) = explode('::', $arg);


        $doc = $this->_db->$cls->findOne(array('_oid' => $arg));

        // @todo: handle error if not found

        return $this->_rebuild($doc);
    }

    /**
     * Remove an object from the Database
     *
     * @param string $arg Object-ID of the object to be removed or the object itself
     * @return boolean true on success, else false
     * @author Axel Tietje
     */
    function delete($arg)
    {
        if (is_string($arg)) {
            $_oid = $arg;
        } elseif (is_object($arg) && isset($arg->_oid)) {
            $_oid = $arg->_oid;
        } elseif (is_object($arg) && !isset($arg->_oid)) {
            throw new InvalidArgumentException('Tugrik::drop() only accepts objects with valid _oid');
            $_oid = $arg->_oid;
        } else {
            throw new InvalidArgumentException('Tugrik::drop() only accepts strings or stored objects');
            return false;
        }
        
        list($cls, $oid) = explode('::', $_oid);
        
        if (in_array($cls, $this->_db->listCollections(), true)) {
            throw new Exception("Collection '$cls' does not exist.");
            return false;
        }
        
        try {
            $result = $this->_db->$cls->remove(
                array('_oid' => $_oid),
                array('justOne' => true, 'safe' => true)
            );
        } catch (MongoCursorException $e) {
            return false;
        }
        
        unset($this->_objects[$_oid]);
        if (is_object($arg)) {
            unset($arg->_oid);
            unset($arg->_hash);
        }

        return true;
    }

    public function _reflect($var, array &$doc=array(), $path='', $poid='')
    {
        static $ref = array();

        if ($path !== '') {
            $path = "{$path}.";
        }

        if (is_object($var)) {
            if ($var instanceof stdClass) {
                throw new InvalidArgumentException('Tugrik cannot store instances of stdClass');
                return false;
            }
            // init some vars
            $doUpdate = false;
            $storedDoc = null;
            $storedOid = $storedHash = '';
            // get the class to be stored
            $cls = get_class($var);
            if (isset($var->_oid)) {
                $storedDoc = $this->_db->$cls->findOne(array('_oid' => $var->_oid));
                if (is_array($storedDoc) &&
                    $storedDoc['_id'] instanceof MongoId
                ) {
                    $doUpdate = true;
                    $storedOid = $var->_oid;
                    $storedHash = $storedDoc['_hash'];
                    if (!isset($var->_hash) ||
                        $var->_hash !== $storedDoc['_hash']
                    ) {
                        // The object has been altered by some other
                        // process in the time between fetch() and store()
                        // What should be done? 
                        // Overwrite? Throw an Exception?
                        throw new UnexpectedValueException(
                            "Object hash altered from {$var->_hash} to {$storedHash}"
                        );
                    }
                } else {
                    return $var->_oid;
                }
            } else {
                $newOid = $cls . '::' . $this->_newOid();
                $var->_oid = $newOid;
            }
            
            // Keep track of recursions
            if (isset($this->_recursions[$var->_oid])) {
                return $var->_oid;
            }
            
            $this->_recursions[$var->_oid] = true;
            
            // object reflection
            $ref = new ReflectionClass($cls);
            foreach ($ref->getProperties() as $prop) {
                if (!$prop->isPublic()) {
                    $prop->setAccessible(true);
                }
                // $dec = $prop->getDeclaringClass()->getName();
                $key = $prop->getName();
                $val = $prop->getValue($var);
                // print_r("{$dec}->{$key}: $val\n");
                $_path = $path . $key;
                if (is_array($val)) {
                    $doc[$key] = array();
                    $val = $this->_reflect($val, $doc[$key], $_path, $var->_oid);
                } else if (is_object($val)) {
                    $_doc = array();
                    $_ref = $this->_reflect($val, $_doc, $_path, $var->_oid);
                    // keep this order
                    $doc[$key] = $_doc;
                    $doc["*{$key}"] = $_ref;
                    // Remember the “pointer”
                    $this->_db->TugrikMetaPointer->insert(
                        array('owner' => $var->_oid, 'owned' => $_ref, 'path' => $_path),
                        array('owner' => $var->_oid, 'owned' => $_ref, 'path' => $_path)
                    );
                } else {
                    $doc[$key] = $val;
                }
            }
            // build the hash
            $hash = sha1(serialize($doc));
            if ($doUpdate && $storedHash === $hash) {
                return $var->_oid;
            }
            $oid = '';
            // store the “document”
            if (true === $doUpdate) {
                $oid = $storedOid;
                $doc['_hash'] = $hash;
                // Update only if no changes have occurred in the meantime
                try {
                    $this->_db->$cls->update(
                        array(
                            '_oid'  => $storedOid,
                            '_hash' => $storedHash
                        ),
                        array(
                            '$set' => $doc
                        ),
                        array(
                            'upsert' => false,
                            'multiple' => false,
                            'safe' => true
                        )
                    );
                } catch (MongoCursorException $e) {
                    // What should be done now?
                }
            } else {
                $oid = $doc['_oid'] = $newOid;
                $doc['_hash'] = $hash;
                $this->_db->$cls->insert($doc);
            }
            // add _hash to object
            $var->_hash = $hash;
            return $oid;
        } else if (is_array($var)) {
            foreach ($var as $key => $val) {
                $_path = $path . $key;
                if (is_array($val)) {
                    $doc[$key] = array();
                    $val = $this->_reflect($val, $doc[$key], $_path, $poid);
                } else if (is_object($val)) {
                    $_doc = array();
                    $_ref = $this->_reflect($val, $_doc, $_path, $poid);
                    // keep this order
                    $doc[$key] = $_doc;
                    $doc["*{$key}"] = $_ref;
                    // Remember the “pointer”
                    $this->_db->TugrikMetaPointer->insert(
                        array('owner' => $poid, 'owned' => $_ref, 'path' => $_path),
                        array('owner' => $poid, 'owned' => $_ref, 'path' => $_path)
                    );
                } else {
                    $doc[$key] = $val;
                }
            }
        } else {
            return $var;
        }
    }
    
    private function _rebuild(array $doc)
    {
        if (isset($doc['_oid'])) {
            list($cls, $oid) = explode('::', $doc['_oid']);
            try {
                $ref = new ReflectionClass($cls);
            } catch (ReflectionException $e) {
                trigger_error("Class '{$cls} does not exist'", E_USER_ERROR);
                return null;
            }
            $obj = new $cls;
            foreach ($doc as $name => $value) {
                if ('_oid' === $name ||
                    '_hash' === $name
                ) {
                    $obj->$name = $value;
                    continue;
                }
                $realname = $name;
                if (0 === strpos($name, '*')) {
                    $realname = substr($name, 1);
                    unset($doc->realname);
                    $value = $this->fetch($value);
                }
                if (!$ref->hasProperty($realname)) {
                    continue;
                }
                $prop = $ref->getProperty($realname);
                if (!$prop instanceof ReflectionProperty) {
                    continue;
                }
                if (!$prop->isPublic()) {
                    $prop->setAccessible(true);
                }
                $prop->setValue($obj, $value);
            }
            return $obj;
        } else {
            // TODO: check what we might have to do here… 
            // could there ever be an “else”?
            return $doc;
        }
    }

    /**
     * Returns a new object ID
     *
     * @todo make the return value unique
     * @return string
     * @author Axel Tietje
     */
    function _newOid()
    {
        $r = range('a', 'f');
        $oid = $r[array_rand($r, 1)]
             . substr(sha1(microtime().rand(0, 1000000)), 0, 7);

        return $oid;
    }

    // Temporary methods, will possibly change

    function find($collection, array $query=array(), array $fields=array('_oid'))
    {
        return $this->_db->$collection->find($query, $fields);
    }

    function findOne($collection, array $query=array(), array $fields=array())
    {
        return $this->_db->$collection->findOne($query, $fields);
    }

    public function count($collection)
    {
        return $this->_db->$collection->count();
    }

    // Temporary accessors for debugging purposes, will possibly vanish
    
    function db()
    {
        return $this->_db;
    }

    function mongo()
    {
        return $this->_mongo;
    }
}
