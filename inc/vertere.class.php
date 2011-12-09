<?php
include_once MORIARTY_DIR.'moriarty.inc.php';
include_once MORIARTY_DIR.'simplegraph.class.php';
include_once 'conversions.class.php';

class Vertere {

	private $spec, $spec_uri, $resources, $base_uri, $lookups = array();

	public function __construct($spec, $spec_uri) {
		$this->spec = $spec;
		$this->spec_uri = $spec_uri;
		
		//Find resource specs
		$this->resources = $spec->get_resource_triple_values($this->spec_uri, NS_CONV.'resource');
		if (empty($this->resources)) { throw new Exception('Unable to find any resource specs to work from'); }
		
		$this->base_uri = $spec->get_first_literal($this->spec_uri, NS_CONV.'base_uri');
	}
	
	public function convert_array_to_graph($record) {
		$uris = $this->create_uris($record);
		$graph = new SimpleGraph();
		$this->add_default_types($graph, $uris);
		$this->create_relationships($graph, $uris);
		$this->create_attributes($graph, $uris, $record);
		return $graph;
	}
	
	private function add_default_types($graph, $uris) {
		foreach ( $this->resources as $resource ) {
			$types = $this->spec->get_resource_triple_values($resource, NS_CONV.'type');
			foreach ($types as $type) {
				if (!empty($type) && isset($uris[$resource])) {
					$graph->add_resource_triple($uris[$resource], NS_RDF.'type', $type);
				}
			}
		}
	}
	
	private function create_attributes(&$graph, $uris, $record) {
		foreach ( $this->resources as $resource ) {
			$attributes = $this->spec->get_resource_triple_values($resource, NS_CONV.'attribute');
			foreach ($attributes as $attribute) {
				$this->create_attribute($graph, $uris, $record, $resource, $attribute);
			}
		}
	}
	
	private function create_attribute(&$graph, $uris, $record, $resource, $attribute) {
		$subject = $uris[$resource];
		$property = $this->spec->get_first_resource($attribute, NS_CONV.'property');
		$language = $this->spec->get_first_literal($attribute, NS_CONV.'language');
		$datatype = $this->spec->get_first_resource($attribute, NS_CONV.'datatype');

		$source_column = $this->spec->get_first_literal($attribute, NS_CONV.'source_column');
		$source_columns = $this->spec->get_first_resource($attribute, NS_CONV.'source_columns');
		if ($source_column) {
			$source_column--;
			$source_value = $record[$source_column];
		} else if ($source_columns) {
			$source_columns = $this->spec->get_list_values($source_columns);
			$glue = $this->spec->get_first_literal($attribute, NS_CONV.'source_column_glue');
			$source_values = array();
			foreach ($source_columns as $source_column) {
				$source_column = $source_column['value'];
				$source_column--;
				$source_values[] = $record[$source_column];
			}
			$source_value = implode($glue, $source_values);
		} else {
			return;
		}
		
		if (empty($source_value)) { return; }

		$lookup = $this->spec->get_first_resource($attribute, NS_CONV.'lookup');
		if($lookup != null) {
			$lookup_value = $this->lookup($lookup, $source_value);
			if ($lookup_value != null && $lookup_value['type'] == 'uri') {
				$graph->add_resource_triple($subject, $property, $lookup_value['value']);
				return;
			} else {
				$source_value = $lookup_value['value'];
			}
		}
		
		$source_value = $this->process($attribute, $source_value);
		
		$graph->add_literal_triple($subject, $property, $source_value, $language, $datatype);
		
		
	}
	
	private function create_relationships(&$graph, $uris) {
		foreach ( $this->resources as $resource ) {
			$relationships = $this->spec->get_resource_triple_values($resource, NS_CONV.'relationship');
			foreach ($relationships as $relationship) {
				$this->create_relationship($graph, $uris, $resource, $relationship);
			}
		}
	}
	
	private function create_relationship(&$graph, $uris, $resource, $relationship) {
		$subject = $uris[$resource];
		$property = $this->spec->get_first_resource($relationship, NS_CONV.'property');
		$object_from = $this->spec->get_first_resource($relationship, NS_CONV.'object_from');
		$object = $uris[$object_from];
		if ($subject && $property && $object) {
			$graph->add_resource_triple($subject, $property, $object);
		}
	}
	
	private function create_uris($record) {
		$uris = array();
		foreach ( $this->resources as $resource ) {
			if (!isset($uris[$resource])) {
				$identity = $this->spec->get_first_resource($resource, NS_CONV.'identity');
				$this->create_uri($record, $uris, $resource, $identity);
			}
		}
		return $uris;
	}
	
	private function create_uri($record, &$uris, $resource, $identity) {
		$source_column = $this->spec->get_first_literal($identity, NS_CONV.'source_column');
		$source_columns = $this->spec->get_first_resource($identity, NS_CONV.'source_columns');
		
		if ($source_column) {
			$source_column--;
			$source_value = $record[$source_column];
		} else if ($source_columns) {
			$source_columns = $this->spec->get_list_values($source_columns);
			$glue = $this->spec->get_first_literal($identity, NS_CONV.'source_column_glue');
			$source_values = array();
			foreach ($source_columns as $source_column) {
				$source_column = $source_column['value'];
				$source_column--;
				if (!empty($record[$source_column])) {
					$source_values[] = $record[$source_column];
				}
			}
			$source_value = implode('', $source_values);
			if (!empty($source_value)) {
				$source_value = implode($glue, $source_values);
			}
		} else {
			return;
		}

		//Check for lookups
		$lookup = $this->spec->get_first_resource($identity, NS_CONV.'lookup');
		if($lookup != null) {
			$lookup_value = $this->lookup($lookup, $source_value);
			if ($lookup_value != null && $lookup_value['type'] == 'uri') {
				$uris[$resource] = $lookup_value['value'];
				return;
			} else {
				$source_value = $lookup_value['value'];
			}
		}
		
		//Decide on base_uri
		$base_uri = $this->spec->get_first_literal($identity, NS_CONV.'base_uri');
		if ($base_uri === null) { $base_uri = $this->base_uri; }

		//Decide if the resource should be nested (overrides the base_uri)
		$nest_under = $this->spec->get_first_resource($identity, NS_CONV.'nest_under');
		if ($nest_under != null) {
			if (!isset($uris[$nest_under])) {
				$parent_identity = $this->spec->get_first_resource($nest_under, NS_CONV.'identity');
				$this->create_uri($record, $uris, $nest_under, $parent_identity);
			}
			$base_uri = $uris[$nest_under];
			if (!preg_match('%[/#]$%', $base_uri)) { $base_uri .= '/'; }
		}
		
		$container = $this->spec->get_first_literal($identity, NS_CONV.'container');
		if (!empty($container) && !preg_match('%[/#]$%', $container)) { $container .= '/'; }
		
		$source_value = $this->process($identity, $source_value);
		
		if (!empty($source_value)) {
			$uri = "${base_uri}${container}${source_value}";
			$uris[$resource] = $uri;
		} else {
			$identity = $this->spec->get_first_resource($resource, NS_CONV.'alternative_identity');
			$this->create_uri($record, $uris, $resource, $identity);
		}
	}
	
	public function process($resource, $value) {
		$processes = $this->spec->get_first_resource($resource, NS_CONV.'process');
		if ($processes != null) {
			$process_steps = $this->spec->get_list_values($processes);
			foreach ($process_steps as $step) {
				switch ($step['value']) {
					case NS_CONV.'normalise':
						$value = strtolower(str_replace(' ', '_', trim($value)));
						break;

					case NS_CONV.'title_case':
						$value = ucwords($value);
						break;

					case NS_CONV.'regex':
						$regex_pattern = $this->spec->get_first_literal($resource, NS_CONV.'regex_match');
						foreach (array('%','/','@','!','^',',','.','-') as $candidate_delimeter) {
							if(strpos($candidate_delimeter, $regex_pattern) === false) {
								$delimeter = $candidate_delimeter;
								break;
							}
						}
						$regex_output = $this->spec->get_first_literal($resource, NS_CONV.'regex_output');
						$value = preg_replace("${delimeter}${regex_pattern}${delimeter}", $regex_output, $value);
						break;
						
					case NS_CONV.'feet_to_metres':
						$value = Conversions::feet_to_metres($value);
						break;
						
					case NS_CONV.'round':
						$value = round($value);
						break;
						
					default:
						throw new Exception("Unknown process requested: ${step}");
				}
			}
		}
		return $value;
	}
	
	public function lookup($lookup, $key) {
		//Make lookups quicker by banging them into a PHP array
		if (!isset($this->lookups[$lookup])) {
			$entries = $this->spec->get_subject_property_values($lookup, NS_CONV.'lookup_entry');
			if (empty($entries)) { throw new Exception("Lookup ${lookup} had no lookup entries"); }
			foreach ($entries as $entry) {
				//Accept lookups with several keys mapped to a single value
				$lookup_keys = $this->spec->get_subject_property_values($entry['value'], NS_CONV.'lookup_key');
				foreach ($lookup_keys as $lookup_key_array) {
					$lookup_key = $lookup_key_array['value'];
					if (isset($this->lookups[$lookup][$lookup_key])) { throw new Exception("Lookup <${lookup}> contained a duplicate key"); }
					$lookup_values = $this->spec->get_subject_property_values($entry['value'], NS_CONV.'lookup_value');
					if (count($lookup_values) != 1) { throw new Exception("Lookup ${lookup} has an entry ${entry['value']} that does not have exactly one lookup value assigned."); }
					$this->lookups[$lookup][$lookup_key] = $lookup_values[0];
				}
			}
		}
		return isset($this->lookups[$lookup][$key]) ? $this->lookups[$lookup][$key] : null;
	}

	public static function normalise($part) {
		return rawurlencode(str_replace(' ', '_', strtolower(trim($part))));
	}

}
