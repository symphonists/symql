<?php

require_once('class.symqlquery.php');

Class SymQL {
	
	protected static $_instance;
	
	const SELECT_COUNT = 0;
	const SELECT_ENTRY_ID = 1;
	const SELECT_ENTRIES = 2;
	
	const QUERY_AND = 'AND';
	const QUERY_OR = 'OR';
	
	const RETURN_XML = 0;
	const RETURN_ARRAY = 1;
	const RETURN_RAW_COLUMNS = 2;
	const RETURN_ENTRY_OBJECTS = 3;
	
	private static $entryManager = null;
	private static $sectionManager = null;
	private static $fieldManager = null;
	
	private static $reserved_fields = array('*', 'system:count', 'system:id', 'system:date');
	
	private static function init() {
		if (!(self::$_instance instanceof SymQL)) {
			self::$_instance = new self;
		}
	}
	
	public function __construct() {
		
		if(class_exists('Frontend')){
			$context = Frontend::instance();
		} else {
			$context = Administration::instance();
		}
		
		self::$entryManager = new EntryManager($context);
		self::$sectionManager = self::$entryManager->sectionManager;
		self::$fieldManager = self::$entryManager->fieldManager;
	}
	
	/*
		Given an XMLElement, iterates and converts children into an array
	*/
	private function xmlElementToArray($node, $is_field_data=false){

		$result = array();

		if(count($node->getAttributes()) > 0){
			foreach($node->getAttributes() as $attribute => $value ){
				if(strlen($value) != 0){
					$result[$node->getName()]['_' . $attribute] = $value;
				}
			}				
		}

		$value = $node->getValue();
		if (!is_null($value)) $result[$node->getName()]['value'] = $node->getValue();

		$numberOfchildren = $node->getNumberOfChildren();

		if($numberOfchildren > 0 || strlen($node->getValue()) != 0){

			if($numberOfchildren > 0 ) {

				foreach($node->getChildren() as $child) {

					$next_child_is_field_data = ($child->getName() == 'entry');

					if ($is_field_data == true) {
						if(($child instanceof XMLElement)) $result[$node->getName()]['fields'][] = self::xmlElementToArray($child, $next_child_is_field_data);
					} else {
						if(($child instanceof XMLElement)) $result[$node->getName()][] = self::xmlElementToArray($child, $next_child_is_field_data);
					}
				}

			}			
		}

		return $result;
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
		foreach ($fields_list as $field) {
			$field = trim($field);
			$remove = true;

			if (in_array($field, self::$reserved_fields)) {
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
	
	public static function run(SymQLQuery $query, $output=SymQL::RETURN_XML) {
		
		self::init();
		
		// stores all config locally so that the same SymQLManager can be used for mutliple queries
		$section = null;
		$section_fields = array();
		$where = null;
		$joins = null;			
		$entry_ids = array();
		
		// resolve section
		if (!is_numeric($query->section)) $section = self::$sectionManager->fetchIDFromHandle($query->section);
		$section = self::$sectionManager->fetch($section);
		if (!$section instanceof Section) throw new Exception(sprintf("%s: section '%s' does not not exist", __CLASS__, $query->section));
		
		// cache list of field objects in this section (id => object)
		$fields = $section->fetchFields();
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
		foreach ($query->filters as $i => $filter) {
			$field = self::indexFieldsByID($filter['field'], $fields);
			if ($field) {
				$filters[$i][reset(array_keys($field))]['value'] = $filter['value'];
				$filters[$i][reset(array_keys($field))]['type'] = $filter['type'];
			}
		}
		
		// resolve sort field
		if (in_array($query->sort_field, self::$reserved_fields)) {
			$handle_exploded = explode(':', $query->sort_field);
			if (count($handle_exploded) == 2) {
				self::$entryManager->setFetchSorting(end($handle_exploded), $query->sort_direction);
			}
		} else {
			$sort_field = self::indexFieldsByID($query->sort_field, $fields);
			$sort_field = $section_fields[reset(array_keys($sort_field))];
			if ($sort_field && $sort_field->isSortable()) {
				self::$entryManager->setFetchSorting($sort_field->get('id'), $query->sort_direction);
			}
		}			
		
		$where = null;
		$joins = null;
		
		foreach ($filters as $filter) {
			$field_id = reset(array_keys($filter));
			$filter = reset($filter);
			
			if ($field_name == 'system:id') {
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
			
			// Get the WHERE and JOIN from the field
			$where_before = $_where;
			$field->buildDSRetrivalSQL(array($filter['value']), $_joins, $_where, true);
			
			// HACK: if this is an OR statement, strip the first AND from the returned SQL
			// and replace with OR
			if ($filter['type'] == SymQL::QUERY_OR) {
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
			$fetch_result = (int)self::$entryManager->fetchCount(
				$section->get('id'),
				$where,
				$joins
			);
		}
		else if (count($entry_ids) > 0) {
			$select_type = SymQL::SELECT_ENTRY_ID;
			$fetch_result = self::$entryManager->fetch(
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
			$fetch_result = self::$entryManager->fetchByPage(
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
				$result = new XMLElement('query');
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
							$result['entries'][] = reset(self::xmlElementToArray($xml_entry, true));
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
		
		// reset for the next query
		self::$entryManager->setFetchSorting(null, null);
		
		return $result;
		
	}
	
}