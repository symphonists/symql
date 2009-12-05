# SymQL
Version: 0.1  
Author: [Nick Dunn](http://nick-dunn.co.uk)  
Build Date: 05 December 2009  
Requirements: Symphony integration branch

## Usage
Include the SymQL class:

	require_once(EXTENSIONS . '/symql/lib/class.symql.php');

Create a new SymQL passing it front or backend context:

	$symql = new SymQL(Frontend::instance());

Build a new query:

	$query = new SymQLQuery();

The following methods can be called on the query:

### `select`
Specify the fields to return for each entry. Accepts a comma-delimited string of field handles or IDs. For example:

	$query->select('*'); // all fields

	$query->select('name, body, published'); // field handles

	$query->select('name, 4, 12, comments:items'); // mixture of handles, IDs and fields with "modes" e.g. Bi-Link

	$query->select('system:count'); // count only

### `from`
Specify the section from which entries should be queried. Accepts either a section handle or ID. For example:

	$query->from('articles');

### `where`
Build a series of select criteria. Use DS-filtering syntax. Accepts a field (handle or ID), the filter value, and an optional type.For example:

	$query->where('published', 'yes'); // filters a checkbox with a Yes value

	$query->where('title', 'regexp:CSS'); // text input REGEX filter

As with a Data Source, filters combine at the SQL level with the AND keyword. Therefore using the two filters above would select entries where published=yes AND title is like CSS. If you want the filters to concatenate using the OR keyword, pass your preference as the optional third argument:

	$query->where('title', 'regexp:Nick', SymQL::QUERY_OR);
	$query->where('title', 'regexp:Dunn', SymQL::QUERY_OR);
	$query->where('published', 'yes', SymQL::QUERY_AND);

The above will find published entries where the title matches either Nick or Dunn. Note that the default is `SymQL::QUERY_AND` so this doesn't need to be set explicitly on the third `where` above.

System parameters can also be filtered on, as with a normal Data Source:

	$query->where('system:id', 15);

	$query->where('system:date', 'today);

### `orderby`
Specify sort and direction. Defaults to `system:id` in `desc` order.

	$query->orderby('system:date', 'asc');

	$query->orderby('name', 'desc');

### `perPage`
Number of entries to limit by. If you don't want to use pagination, use this as an SQL `LIMIT`.

	$query->perPage(20);

### `page`
Which page of entries to return.

	$query->page(2);

## Run the query!
To run the SymQLQuery object, pass it to the SymQL's `run` method:

	$result = $symql->run($query);

SymQL can return entries in four different flavours depending on how you want them. Pass the output mode as the second argument to the `run` method. For example:

	$result = $symql->run($query, SymQL::RETURN_XML); // XML is the default

 On the menu today:

### `RETURN_XML` (default)
Returns an XMLElement object, like a Data Source.

### `RETURN_ARRAY`
Returns the same structure as XML, but as a PHP array instead.

### `RETURN_RAW`
Rather than entries returned as they would be through a Data Source, this options returns the raw column values from the database.

### `RETURN_FIELDS`
Returns an array of Field objects which can be individually modified and re-saved.

## Known bugs

* select 'system:count' not yet implemented, still looks for 'count'
* serialising XMLElement into an array doesn't produce a very clean array
* RETURN_FIELDS does and should not return "Fields". It returns ENTRIES! Incorrectly named, needs reworking.