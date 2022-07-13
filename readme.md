# Leven ORM

## Features
- 💾 easily map pure PHP objects to a database
- ⛓ intuitive relationship mapping
- 🔧 configure entities with PHP8 attributes
- 🧪 easily testable with a mock database - uses [Leven Database Adapter](https://github.com/leven-framework/dba-common)
- 🔤 automatic table and column name mapping
- 🐌 automatic eager loading and caching
- 🔝 support for auto-incrementing primary props
- 📁 all props are stored JSON-encoded in a single column

## Example

```php
require 'vendor/autoload.php';

$repo = new \Leven\ORM\Repository(
    new \Leven\DBA\MySQL\MySQLAdapter(
        database: 'example',
        user: 'username',
        password: 'password',
    )
);

(new \Leven\ORM\RepositoryConfigurator($repo))
    ->scanEntityClasses();

class Author extends \Leven\ORM\Entity {
    #[\Leven\ORM\Attribute\PropConfig(primary: true)]
    public int $id;
    
    public function __construct(
        public string $name;
    ){}
}

class Book extends \Leven\ORM\Entity {
    #[\Leven\ORM\Attribute\PropConfig(primary: true)]
    public int $id;
    
    public function __construct(
        // this defines that each Book must belong to an Author
        public Author $author;
        
        // we can provide rules for the Book's title
        #[\Leven\ORM\Attribute\ValidationConfig(notEmpty: true, maxLength: 256)]
        public string $title;
        
        // store this prop in a separate column, so we can search for entities by it
        #[\Leven\ORM\Attribute\PropConfig(index: true)]
        public string $isbn;
        
        // when storing or reading to the db, we'll use a converter to convert this prop to/from a scalar value
        #[\Leven\ORM\Attribute\PropConfig(converter: \Leven\ORM\Converter\DateTimeStringConverter::class)]
        public DateTime $releaseDate;
    ){}
}

$john = new Author('John Doe');
$example = new Book($author, 'Example Book', '123456789', new DateTime('2021-01-01'));
$repo->store($john, $example);

// later...

$author = $repo->get(Author::class, 1); // get author with id 1
$books = $repo->findChildrenOf($author, Book::class)->get();

$book = $repo->find(Book::class)->where('isbn', '123456789')->getFirst();
$book->title = 'New Title';
$repo->update($book);
```