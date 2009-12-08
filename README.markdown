# SymQL
Version: 0.3  
Author: [Nick Dunn](http://nick-dunn.co.uk)  
Build Date: 07 December 2009  
Requirements: Symphony integration branch

## Usage
Include the SymQL class:

	require_once(EXTENSIONS . '/symql/lib/class.symql.php');

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
Specify sort and direction. Defaults to `system:id` in `desc` order. Use a valid field ID, handle or system pseudo-field (system:id, system:date). Direction values are 'asc', 'desc' or 'rand'. Case insensitive. 

	$query->orderby('system:date', 'asc');

	$query->orderby('name', 'DESC');

### `perPage`
Number of entries to limit by. If you don't want to use pagination, use this as an SQL `LIMIT`.

	$query->perPage(20);

### `page`
Which page of entries to return.

	$query->page(2);

## Run the query!
To run the SymQLQuery object, pass it to the SymQL's `run` method:

	$result = SymQL::run($query); // returns an XMLElement of matching entries

SymQL can return entries in four different flavours depending on how you want them. Pass the output mode as the second argument to the `run` method. For example:

	$result = SymQL::run($query, SymQL::RETURN_ENTRY_OBJECTS); // returns an array of Entry objects

* `RETURN_XML` (default) returns an XMLElement object, almost identical to a Data Source output
* `RETURN_ARRAY` returns the same structure as RETURN_XML above, but the XMLElement is converted into a PHP array
* `RETURN_RAW_COLUMNS` returns the raw column values from the database for each field
* `RETURN_ENTRY_OBJECTS` returns an array of Entry objects, useful for further processing

## A simple example
Try this inside a customised Data Source. The DS should return $result, which by default is returned from SymQL as an XMLElement.

	// include SymQL
	require_once(EXTENSIONS . '/symql/lib/class.symql.php');

	// create a new SymQLQuery
	$query = new SymQLQuery();
	$query
		->select('title, content, date, publish')
		->from('articles')
		->where('published', 'yes')
		->orderby('system:date', 'desc')
		->perPage(10)
		->page(1);

	// run it! by default an XMLElement is returned
	$result = SymQL::run($query);

## Debugging
Basic debug information can be ascertained by calling `SymQL::getDebug()` after running your query. It returns an array of query counts and SQL fragments.

	var_dump(SymQL::getDebug());die;

## Known issues
* serialising XMLElement into an array doesn't produce a very clean array

## Changelog

* 0.3, 07 December 2009
	* SymQL is now a static class

* 0.2, 07 December 2009
	* changed `count` to `select:count` to avoid naming conflicts
	* renamed RETURN_FIELDS to RETURN_ENTRY_OBJECTS and RETURN_RAW to RETURN_RAW_COLUMNS for clarity
	* updated documentation
	* fixed extension.driver.php

* 0.1, 05 December 2009
	* initial release. Woo! Yay! Fanfare.