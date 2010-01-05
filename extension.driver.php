<?php

	Class Extension_SymQL extends Extension {

		public function about(){
			return array('name' => 'SymQL',
						 'version' => '0.6',
						 'release-date' => '2010-01-05',
						 'author' => array('name' => 'Nick Dunn',
										   'website' => 'http://nick-dunn.co.uk'),
						'description' => 'An SQL-like syntax for querying entries from Symphony CMS'
					);

		}
	}

?>