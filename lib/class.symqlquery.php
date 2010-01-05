<?php

Class SymQLQuery {
	
	public $root_element = null;
	public $section = null;
	public $fields = null;
	public $filters = null;
	public $page = 1;
	public $per_page = 20;
	public $sort_field = 'system:id';
	public $sort_direction = 'desc';
	
	public function __construct($root_element=null) {
		$this->root_element = $root_element;
		return $this;
	}

	public function select($fields) {
		if ($fields == '*') {
			$this->fields = $fields;
		} else {
			$fields = preg_replace('/ /', '', $fields);
			$this->fields = explode(',', $fields);
		}
		return $this;
	}

	public function from($section) {
		$this->section = $section;
		return $this;
	}

	public function where($field, $condition, $type=SymQL::DS_FILTER_AND) {
		if (!is_array($this->filters)) $this->filters = array();
		$this->filters[] = array(
			'field' => $field,
			'value' => $condition,
			'type' => $type
		);
		return $this;
	}
	
	public function page($page) {
		if (!is_numeric($page) || (int)$page == 0) $page = 1;
		$this->page = (int)$page;
		return $this;
	}
	
	public function perPage($per_page) {
		if (!is_numeric($per_page) || (int)$per_page == 0) $per_page = 1;
		$this->per_page = (int)$per_page;
		return $this;
	}

	public function orderby($field, $direction) {
		$this->sort_field = $field;
		$this->sort_direction = $direction;
		return $this;
	}

}