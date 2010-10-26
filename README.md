Licensed under the MIT license, see LICENSE.txt

Tugrik - ORM for PHP and MongoDB
================================

Tugrik is an abstraction layer for MongoDB written in PHP.

It allows you to store PHP objects directly in a MongoDB database
without converting them or using any XML files or the like. 

It handles relations between objects. Events will be introduced soon.

* Requires PHP 5.3+
* Pre-alpha state - use at your own risk.

Usage - very simple example
---------------------------
    
    // Create some random objects
    $email = new Email('John Doe <john@doe.com>');
    
    $author = new Author('John Doe');
    $author->setEmail($email);

    $book = new Book;
    $book->title = 'On Interesting Relations Pt. I';
    $book->setAuthor($author);

    // Setup Tugrik
    Tugrik::setup('MyDatabaseName', 'mongodb://localhost:27017');
    
    // Get Tugrik singleton
    $tugrik = Tugrik::singleton();
    
    // Store everything
    $tugrik->store($book);
    
    // Search
    $result = $tugrik->find('Author', array('name' => 'John Doe'))->getNext();
    
    // Fetch
    $storedAuthor = $tugrik->fetch($res['_oid']);
