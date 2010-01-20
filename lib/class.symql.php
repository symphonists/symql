<?php

require_once(TOOLKIT . '/class.entrymanager.php');

require_once('class.symqlquery.php');
require_once('class.xmltoarray.php');

Class SymQL {
	
	protected static $_instance;
	private static $_debug = null;
	private static $_base_querycount = null;
	private static $_cumulative_querycount = null;
	
	const SELECT_COUNT = 0;
	const SELECT_ENTRY_ID = 1;
	const SELECT_ENTRIES = 2;
	
	const RETURN_XML = 0;
	const RETURN_ARRAY = 1;
	const RETURN_RAW_COLUMNS = 2;
	const RETURN_ENTRY_OBJECTS = 3;
	
	const DS_FILTER_AND = 1;
	const DS_FILTER_OR = 2;
	
	private static $_context = null;
	private static $_entryManager = null;
	private static $_sectionManager = null;
	private static $_fieldManager = null;
	
	private static $_resolved_sections = array();
	private static $_resolved_fields = array();
	
	private static $_reserved_fields = array('*', 'system:count', 'system:id', 'system:date');
	
	private static function init() {
		if (!(self::$_instance instanceof SymQL)) {
			self::$_instance = new self;
		}
	}
	
	public function __construct() {
		
		if(class_exists('Frontend')){
			self::$_context = Frontend::instance();
		} else {
			self::$_context = Administration::instance();
		}
		
		self::$_entryManager = new EntryManager(self::$_context);
		self::$_sectionManager = self::$_entryManager->sectionManager;
		self::$_fieldManager = self::$_entryManager->fieldManager;
	}
	
	public function debug($enabled=true) {
		self::$_debug = $enabled;
	}
	
	private function getResolvedSection($section) {
		foreach(self::$_resolved_sections as $s) {
			if ((is_numeric($section) && (int)$section == $s->get('id')) || $section == $s->get('handle')) {
				return $s;
			}
		}
	}
	
	private function getResolvedFields($section_id) {
		foreach(self::$_resolved_sections as $s) {
			if ((int)$section_id == $s->get('id')) {
				return self::$_resolved_fields[$section_id];
			}
		}
	}
	
	/*
		Given an array of fields ($fields_list), which can be mixed handles and IDs
		Compares against a list of all fields within a section
		Removes duplicates
		Throws an exception for fields that do not exist
		Returns an array of fields indexed by their ID
	*/
	private function indexFieldsByID($fields_list, $section_fields, $return_object=false) {
		if (!is_array($fields_list) && !is_null($fields_list)) $fields_list = array($fields_list);
		
		$fields = array();
		if (!is_array($fields_list)) $fields_list = array($fields_list);	
		foreach ($fields_list as $field) {
			$field = trim($field);
			$remove = true;

			if (in_array($field, self::$_reserved_fields)) {
				$fields[$field] = null;
				$remove = false;
			}
			
			$field_name = $field;
			if (!is_numeric($field_name)) $field_name = reset(explode(':', $field_name));

			foreach ($section_fields as $section_field) {
				if ($section_field->get('element_name') == $field_name || $section_field->get('id') == (int)$field_name) {
					$fields[$section_field->get('id')] = (($return_object) ? $section_field : ((is_numeric($field_name) ? $field_name : $field)));
					$remove = false;
				}
			}
			
			if ($remove) throw new Exception(sprintf("%s: field '%s' does not exist", __CLASS__, $field));

		}
		
		return $fields;
	}
	
	private static function getQueryCount() {
		if (is_null(self::$_cumulative_querycount)) {
			self::$_base_querycount = self::$_context->Database->queryCount();
			self::$_cumulative_querycount = self::$_context->Database->queryCount();
			return 0;
		}
		$count = self::$_context->Database->queryCount() - self::$_cumulative_querycount;
		self::$_cumulative_querycount = self::$_context->Database->queryCount();
		return $count;
	}
	
	public static function getDebug() {
		return self::$_debug;
	}
	
	public static function run(SymQLQuery $query, $output=SymQL::RETURN_XML) {
		
		self::init();
		
		self::getQueryCount();
		
		// stores all config locally so that the same SymQLManager can be used for mutliple queries
		$section = null;
		$section_fields = array();
		$where = null;
		$joins = null;			
		$entry_ids = array();
		
		// resolve section
		$resolved_section = self::getResolvedSection($query->section);
		
		if (is_null($resolved_section)) {
			$section = $query->section;
			if (!is_numeric($query->section)) $section = self::$_sectionManager->fetchIDFromHandle($query->section);
			$section = self::$_sectionManager->fetch($section);
			if (!$section instanceof Section) throw new Exception(sprintf("%s: section '%s' does not not exist", __CLASS__, $query->section));
			$fields = $section->fetchFields();
			self::$_resolved_sections[] = $section;
			self::$_resolved_fields[$section->get('id')] = $fields;
		}
		else {
			$section = $resolved_section;
			$fields = self::getResolvedFields($section->get('id'));
		}
		
		self::$_debug['queries']['Resolve section and fields'] = self::getQueryCount();
		
		// cache list of field objects in this section (id => object)
		foreach($fields as $field) {
			$section_fields[] = $field->get('id');
		}
		$section_fields = self::indexFieldsByID($section_fields, $fields, true);
		
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
				self::$_entryManager->setFetchSorting(end($handle_exploded), $query->sort_direction);
			}
		} else {
			$sort_field = self::indexFieldsByID($query->sort_field, $fields);
			$sort_field = $section_fields[reset(array_keys($sort_field))];
			if ($sort_field && $sort_field->isSortable()) {
				self::$_entryManager->setFetchSorting($sort_field->get('id'), $query->sort_direction);
			}
		}
		
		$where = null;
		$joins = null;
		
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
			if (!$field->canFilter() || !method_exists($field, 'buildDSRetrivalSQL')) throw new Exception(sprintf("%s: field '%s' can not be used as a filter", __CLASS__, $field_id));
			
			// local
			$_where = null;
			$_joins = null;
			
			$filter_type = (false === strpos($filter['value'], '+') ? self::DS_FILTER_OR : self::DS_FILTER_AND);
			$value = preg_split('/'.($filter_type == self::DS_FILTER_AND ? '\+' : ',').'\s*/', $filter['value'], -1, PREG_SPLIT_NO_EMPTY);
			
			// Get the WHERE and JOIN from the field
			$where_before = $_where;
			$field->buildDSRetrivalSQL(array($filter['value']), $_joins, $_where, ($filter_type == self::DS_FILTER_AND ? true : false));
			
			// HACK: if this is an OR statement, strip the first AND from the returned SQL
			// and replace with OR
			if ($filter['type'] == SymQL::DS_FILTER_OR) {
				$_where_after = substr($_where, strlen($_where_before), strlen($where));
				$_where_after = preg_replace('/^AND/', 'OR', trim($_where_after));
				$_where = $_where_before . $_where_after;
			}
			
			$joins .= $_joins;
			$where .= $_where;
		}
		
		// resolve the SELECT type and fetch entries
		if (reset(array_keys($select_fields)) == 'system:count') {
			$select_type = SymQL::SELECT_COUNT;
			$fetch_result = (int)self::$_entryManager->fetchCount(
				$section->get('id'),
				$where,
				$joins
			);
		}
		else if (count($entry_ids) > 0) {
			$select_type = SymQL::SELECT_ENTRY_ID;
			$fetch_result = self::$_entryManager->fetch(
				$entry_ids,
				$section->get('id'),
				null,
				null,
				null,
				false,
				false,
				true,
				array_values($select_fields)
			);
		}
		else {
			$select_type = SymQL::SELECT_ENTRIES;
			$fetch_result = self::$_entryManager->fetchByPage(
				$query->page,
				$section->get('id'),
				$query->per_page,
				$where,
				$joins,
				false,
				false,
				true,
				array_values($select_fields)
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
		
		self::$_debug['queries']['Total'] = self::$_context->Database->queryCount() - self::$_base_querycount;
		
		// reset for the next query
		self::$_entryManager->setFetchSorting(null, null);
		self::$_base_querycount = null;
		self::$_cumulative_querycount = null;
		
		return $result;
		
	}
	
}