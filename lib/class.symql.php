<?php

require_once(TOOLKIT . '/class.entrymanager.php');

require_once('class.symqlquery.php');
if(!class_exists('XMLToArray')) require_once('class.xmltoarray.php');

Class SymQL {

	protected static $_instance;
	private static $_debug = NULL;
	private static $_base_querycount = NULL;
	private static $_cumulative_querycount = NULL;

	const SELECT_COUNT = 0;
	const SELECT_ENTRY_ID = 1;
	const SELECT_ENTRIES = 2;

	const RETURN_XML = 0;
	const RETURN_ARRAY = 1;
	const RETURN_RAW_COLUMNS = 2;
	const RETURN_ENTRY_OBJECTS = 3;

	const DS_FILTER_AND = 1;
	const DS_FILTER_OR = 2;

	private static $_reserved_fields = array('*', 'system:count', 'system:id', 'system:date');

	public function debug($enabled=TRUE) {
		self::$_debug = $enabled;
	}

	/*
		Given an array of fields ($fields_list), which can be mixed handles and IDs
		Compares against a list of all fields within a section
		Removes duplicates
		Throws an exception for fields that do not exist
		Returns an array of fields indexed by their ID
	*/
	private function indexFieldsByID($fields_list, $section_fields, $return_object=FALSE) {
		if (!is_array($fields_list) && !is_null($fields_list)) $fields_list = array($fields_list);

		$fields = array();
		if (!is_array($fields_list)) $fields_list = array($fields_list);
		foreach ($fields_list as $field) {
			$field = trim($field);
			$remove = TRUE;

			if (in_array($field, self::$_reserved_fields)) {
				$fields[$field] = NULL;
				$remove = FALSE;
			}

			$field_name = $field;
			if (!is_numeric($field_name)) $field_name = reset(explode(':', $field_name));

			foreach ($section_fields as $section_field) {
				if ($section_field->get('element_name') == $field_name || $section_field->get('id') == (int)$field_name) {
					$fields[$section_field->get('id')] = (($return_object) ? $section_field : ((is_numeric($field_name) ? $field_name : $field)));
					$remove = FALSE;
				}
			}

			if ($remove) throw new Exception(sprintf("%s: field '%s' does not exist", __CLASS__, $field));

		}

		return $fields;
	}

	private static function getQueryCount() {
		if (is_null(self::$_cumulative_querycount)) {
			self::$_base_querycount = Symphony::Database()->queryCount();
			self::$_cumulative_querycount = Symphony::Database()->queryCount();
			return 0;
		}
		$count = Symphony::Database()->queryCount() - self::$_cumulative_querycount;
		self::$_cumulative_querycount = Symphony::Database()->queryCount();
		return $count;
	}

	public static function getDebug() {
		return self::$_debug;
	}

	public static function run(SymQLQuery $query, $output=SymQL::RETURN_XML) {

		self::getQueryCount();

		// stores all config locally so that the same SymQL instance can be used for mutliple queries
		$section = NULL;
		$section_fields = array();
		$where = NULL;
		$joins = NULL;
		$entry_ids = array();

		// get a section's ID if it was specified by its handle
		if (!is_numeric($query->section)) $query->section = SectionManager::fetchIDFromHandle($query->section);

		// get the section
		$section = SectionManager::fetch($query->section);
		if (!$section instanceof Section) throw new Exception(sprintf("%s: section '%s' does not not exist", __CLASS__, $query->section));

		// cache the section's fields
		$fields = $section->fetchFields();

		self::$_debug['queries']['Resolve section and fields'] = self::getQueryCount();

		// cache list of field objects in this section (id => object)
		foreach($fields as $field) {
			$section_fields[] = $field->get('id');
		}
		$section_fields = self::indexFieldsByID($section_fields, $fields, TRUE);

		// resolve list of fields from SELECT statement
		if ($query->fields == '*') {
			foreach ($fields as $field) {
				$select_fields[] = $field->get('element_name');
			}
		}
		else {
			$select_fields = $query->fields;
		}
		$select_fields = self::indexFieldsByID($select_fields, $fields);

		// resolve list of fields from WHERE statements (filters)
		$filters = array();

		if (is_array($query->filters)) {
			foreach ($query->filters as $i => $filter) {
				$field = self::indexFieldsByID($filter['field'], $fields);
				if ($field) {
					$filters[$i][reset(array_keys($field))]['value'] = $filter['value'];
					$filters[$i][reset(array_keys($field))]['type'] = $filter['type'];
				}
			}
		}

		// resolve sort field
		if (in_array($query->sort_field, self::$_reserved_fields)) {
			$handle_exploded = explode(':', $query->sort_field);
			if (count($handle_exploded) == 2) {
				EntryManager::setFetchSorting(end($handle_exploded), $query->sort_direction);
			}
		} else {
			$sort_field = self::indexFieldsByID($query->sort_field, $fields);
			$sort_field = $section_fields[reset(array_keys($sort_field))];
			if ($sort_field && $sort_field->isSortable()) {
				EntryManager::setFetchSorting($sort_field->get('id'), $query->sort_direction);
			}
		}

		$where = NULL;
		$joins = NULL;

		foreach ($filters as $filter) {
			$field_id = reset(array_keys($filter));
			$filter = reset($filter);

			if ($field_id == 'system:id') {
				$entry_ids[] = (int)$filter['value'];
				continue;
			}

			// get the cached field object
			$field = $section_fields[$field_id];
			if (!$field) throw new Exception(sprintf("%s: field '%s' does not not exist", __CLASS__, $field_id));
			if (!$field->canFilter() || !method_exists($field, 'buildDSRetrievalSQL')) throw new Exception(sprintf("%s: field '%s' can not be used as a filter", __CLASS__, $field_id));

			// local
			$_where = NULL;
			$_joins = NULL;

			$filter_type = (FALSE === strpos($filter['value'], '+') ? self::DS_FILTER_OR : self::DS_FILTER_AND);
			$value = preg_split('/'.($filter_type == self::DS_FILTER_AND ? '\+' : ',').'\s*/', $filter['value'], -1, PREG_SPLIT_NO_EMPTY);
			$value = array_map('trim', $value);

			// Get the WHERE and JOIN from the field
			$where_before = $_where;
			$field->buildDSRetrievalSQL(array($filter['value']), $_joins, $_where, ($filter_type == self::DS_FILTER_AND ? TRUE : FALSE));

			// HACK: if this is an OR statement, strip the first AND from the returned SQL
			// and replace with OR. This is quite brittle, but the only way I could think of
			if ($filter['type'] == SymQL::DS_FILTER_OR) {
				// get the most recent SQL added
				$_where_after = substr($_where, strlen($_where_before), strlen($where));
				// replace leading AND with OR
				$_where_after = preg_replace('/^AND/', 'OR', trim($_where_after));
				// re-append
				$_where = $_where_before . ' ' . $_where_after;
			}

			$joins .= $_joins;
			$where .= $_where;
		}

		// resolve the SELECT type and fetch entries
		if (reset(array_keys($select_fields)) == 'system:count') {
			$select_type = SymQL::SELECT_COUNT;
			$fetch_result = (int)EntryManager::fetchCount(
				$section->get('id'),
				$where,
				$joins
			);
		}

		else if (count($entry_ids) > 0) {
			$select_type = SymQL::SELECT_ENTRY_ID;
			$fetch_result = EntryManager::fetch(
				$entry_ids, // entry_id
				$section->get('id'), // section_id
				NULL, // limit
				NULL, // start
				NULL, // where
				NULL, // joins
				FALSE, // group
				TRUE, // buildentries
				array_values($select_fields) // element_names
			);
		}


		// $page = 1, $section_id, $entriesPerPage, $where = null, $joins = null, $group = false, $records_only = false, $buildentries = true, Array $element_names = null
		else {
			$select_type = SymQL::SELECT_ENTRIES;
			$fetch_result = EntryManager::fetchByPage(
				$query->page, // page
				$section->get('id'), // section_id
				$query->per_page, // entriesPerPage
				$where, // where
				$joins, // joins
				FALSE, // group
				FALSE, // records_ony
				TRUE, // build_entries
				array_values($select_fields) // element_names
			);
		}

		self::$_debug['sql']['joins'] = $joins;
		self::$_debug['sql']['where'] = $where;

		self::$_debug['queries']['Fetch entries'] = self::getQueryCount();

		// section metadata
		$section_metadata = array(
			'name' => $section->get('name'),
			'id' => $section->get('id'),
			'handle' => $section->get('handle')
		);

		// build pagination metadata
		if ($select_type == SymQL::SELECT_ENTRIES) {
			$pagination = array(
				'total-entries' => (int)$fetch_result['total-entries'],
				'total-pages' => (int)$fetch_result['total-pages'],
				'per-page' => (int)$fetch_result['limit'],
				'current-page' => (int)$query->page
			);
		}

		// find the array of entries returned from EntryManager fetch
		$entries = array();
		switch($select_type) {
			case SymQL::SELECT_ENTRY_ID:
				$entries = $fetch_result;
			break;
			case SymQL::SELECT_ENTRIES:
				$entries = $fetch_result['records'];
			break;
			case SymQL::SELECT_COUNT:
				$count = $fetch_result;
			break;
		}

		// set up result container depending on return type
		switch($output) {
			case SymQL::RETURN_ARRAY:
			case SymQL::RETURN_RAW_COLUMNS:
			case SymQL::RETURN_ENTRY_OBJECTS:
				$result = array();
				$result['section'] = $section_metadata;
				if ($pagination) $result['pagination'] = $pagination;
			break;

			case SymQL::RETURN_XML:
				$result = new XMLElement(($query->root_element) ? $query->root_element : 'symql');
				$result->appendChild(new XMLElement('section', $section_metadata['name'],
					array(
						'id' => $section_metadata['id'],
						'handle' => $section_metadata['handle']
					)
				));
				if ($pagination) $result->appendChild(General::buildPaginationElement(
					$pagination['total-entries'],
					$pagination['total-pages'],
					$pagination['per-page'],
					$pagination['current-page']
				));
			break;

		}

		// append returned entries to results container
		if ($select_type == SymQL::SELECT_ENTRY_ID || $select_type == SymQL::SELECT_ENTRIES) {

			foreach ($entries as $entry) {
				switch($output) {

					case SymQL::RETURN_RAW_COLUMNS:
						$fields = array();
						foreach ($entry->getData() as $field_id => $values) {
							$field = $section_fields[$field_id];
							$fields[$field->get('element_name')] = $values;
						}
						$result['entries'][$entry->get('id')] = $fields;
					break;

					case SymQL::RETURN_ENTRY_OBJECTS:
						$result['entries'][$entry->get('id')] = $entry;
					break;

					case SymQL::RETURN_XML:
					case SymQL::RETURN_ARRAY:
						$xml_entry = new XMLElement('entry');
						$xml_entry->setAttribute('id', $entry->get('id'));

						foreach ($entry->getData() as $field_id => $values) {
							$field = $section_fields[$field_id];
							$handle = $field->get('element_name');

							$handle_exploded = explode(':', $select_fields[$field_id]);
							if (count($handle_exploded) == 2) {
								$mode = end($handle_exploded);
							}

							$field->appendFormattedElement($xml_entry, $values, $encode, $mode);
						}

						if ($output == SymQL::RETURN_ARRAY) {
							$result['entries'][] = XMLToArray::convert($xml_entry->generate());
						} else {
							$result->appendChild($xml_entry);
						}

					break;
				}
			}
		}
		elseif ($select_type == SymQL::SELECT_COUNT) {

			switch($output) {
				case SymQL::RETURN_ARRAY:
				case SymQL::RETURN_RAW_COLUMNS:
				case SymQL::RETURN_ENTRY_OBJECTS:
					$result['count'] = $count;
				break;
				case SymQL::RETURN_XML:
					$xml_entry = new XMLElement('count', $count);
					$result->appendChild($xml_entry);
				break;
			}
		}

		self::$_debug['queries']['Total'] = Symphony::Database()->queryCount() - self::$_base_querycount;

		// reset for the next query
		EntryManager::setFetchSorting(NULL, NULL);
		self::$_base_querycount = NULL;
		self::$_cumulative_querycount = NULL;

		return $result;

	}

}
