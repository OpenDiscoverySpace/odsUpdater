<?php

//
// Updater version: 13.9.4
//
// Copyright (c) 2013-2014 Luis Alberto Lalueza
// http://github.com/luisango
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
// 
// THIS PROGRAM IS DISTRIBUTED IN THE HOPE THAT IT WILL BE USEFUL, BUT
// WITHOUT ANY WARRANTY; WITHOUT EVEN THE IMPLIED WARRANTY OF
// MERCHANTABILITY OR FITNESS FOR A PARTICULAR PURPOSE.
// 

define('UPDATER_DEBUG_ENABLED', true);

/**
 * Reads a directory and subdirectories and stores them as an array
 * @param  string $directory
 * @param  boolean $recursive
 */
function directoryToArray($directory, $recursive) {
    $array_items = array();
    if ($handle = opendir($directory)) {
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                if (is_dir($directory. "/" . $file)) {
                    if($recursive) {
                        $array_items = array_merge($array_items, directoryToArray($directory. "/" . $file, $recursive));
                    }
                    $file = $directory . "/" . $file;
                    $array_items[] = preg_replace("/\/\//si", "/", $file);
                } else {
                    $file = $directory . "/" . $file;
                    $array_items[] = preg_replace("/\/\//si", "/", $file);
                }
            }
        }
        closedir($handle);
    }
    return $array_items;
}

class Updater
{
    /**
     * Prints debug trace
     * @param  string  $string 
     * @param  integer $n Relative spacing
     * @param  string  $separator 
     */
    private function debug($string, $n = 1, $separator = " ")
    {
        if (UPDATER_DEBUG_ENABLED) {
            $n = ($n-1)*4;
            for($i = 0; $i < $n; $i++)
                echo $separator;

            echo $string."\n";
        }
    }

    /**
     * Prepare a node to be stored into db
     * @return stdClass the node
     */
    private function prepareNode()
    {
        $node = new stdClass();
        $node->title = "";
        $node->language = "";
        $node->type = 'educational_object';
        node_object_prepare($node);

        $node->uid = user_load_by_name('social updater')->uid; // Social data user

        return $node;
    }

    /**
     * Creates a node, from start to finish, with the data provided.
     * Data may be provided by ODSDocument parser.
     * @param  array  $data
     */
    public function createNode(array $data)
    {
        $this->debug("Creating node");

        // Static content
        $node = $this->prepareNode();

        foreach($data as $field => $value)
        {
            $this->debug("Creating field: '". $field ."'...", 2);

            // snake_case 2 CamelCase
            $field_function = str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));

            $node = call_user_func(
                __NAMESPACE__ .'\Updater::create'. $field_function .'Field',
                $node,
                $value
            );
        }

        $this->debug("Creating node finished!");

        $this->saveNode($node);
    } 

    /**
     * Saves a node
     * @param  stdClass $node
     */
    private function saveNode($node)
    {   
        $this->debug("Saving node...");

        // PREVENT GHOST NODES
        $has_title      = trim($node->title) != "";
        $has_identifier = trim($node->field_lo_identifier['und'][0]['value']) != "";
        $has_location   = trim($node->field_resource_link['und'][0]['url']) != "";


        if(!$has_title || !$has_identifier || !$has_location) {
            $this->debug("!!!!!!!!!!!!! WARNING: NODE NOT VALID, NOT SAVING !!!!!!!!!!!!!", 6);

            return;
        }

        if($node = node_submit($node)) {
            node_save($node);
        }

        $this->debug("Saving node finished!", 2);
    }

    /*
     * CUSTOM FIELDS TO PARSE EACH CONTENT SEPARATELY, THEY ARE CALLED DYNAMICALLY BY
     * createNode() FUNCTION AND THEY ARE DIRECTLY CONNECTED WITH ODSDOCUMENT RESULT. 
     */

    private function createGeneralIdentifierField($node, $value)
    {
        // Prevent for multiple identifiers
        foreach ($value as $identifier)
            $node->field_lo_identifier['und'][0]['value'] = $identifier;

        return $node;
    }

    private function createGeneralLanguageField($node, $value)
    {
        // Prevent for multiple languages
        foreach ($value as $language) {
            $language = $this->replaceWithHeuristics($language, "languages.ini");
            $node->language = $language;
        }

        return $node;
    }

    private function createGeneralTitleField($node, $value)
    {
        // Presets
        $node->title == "";

        // Prevent for multiple titles
        foreach ($value as $title_languages)
        {
            // For each language in title
            foreach ($title_languages as $title)
            {
                $language = $title['language'];
                $language = $this->replaceWithHeuristics($language, "languages.ini");

                // If title language match resource language
                if($language == $node->language) {
                    $node->title = $title['value'];
                }

                // Set current language translation
                $node->title_field[$language][0]['value'] = $title['value'];
            }

            // If node title has been not set, set it now
            if ($node->title == "") {
                if (is_array($title_languages) && count($title_languages) > 0)
                    $node->title = $title_languages[0]['value'];
            }
        }

        if($node->title === "" || $node->title === null) {
            $node->title = "Missing title";
        }

        return $node;
    }

    private function createGeneralDescriptionField($node, $value)
    {
        $is_first = true;

        // Prevent for multiple descriptions
        foreach ($value as $description_languages)
        {
            foreach($description_languages as $description)
            {
                $language = $description['language'];
                $language = $this->replaceWithHeuristics($language, "languages.ini");

                $node->body[$language][0]['value']   = $description['value'];
                $node->body[$language][0]['summary'] = $description['value'];
                $node->body[$language][0]['format']  = 'filtered_html';

                if($is_first) {
                    // UND BY DEFAULT
                    $node->body['und'][0]['value']   = $description['value'];
                    $node->body['und'][0]['summary'] = $description['value'];
                    $node->body['und'][0]['format']  = 'filtered_html';

                    $is_first = false;
                }
            }
        }

        return $node;
    }

    private function createTechnicalLocationField($node, $value)
    {
        // Prevent for multiple technical location
        foreach ($value as $technical_location)
        {
            $node->field_resource_link['und'][0]['url'] = $technical_location;
            $node->field_resource_link['und'][0]['title'] = "View Resource";

            // Return at first occurrence (only one/first technical location is supposed to be shown)
            return $node;
        }

        return $node;
    }

    private function createRightsCopyrightandotherrestrictionsField($node, $value)
    {
        foreach ($value as $copyright)
        {
            $node->field_copyright['und'][0]['value'] = (strlen($copyright['source']) > 0) ? "Yes" : "No";
        }

        return $node;
    }

    private function createEducationalTypicalagerangeField($node, $value)
    {
        foreach ($value as $agerange_languages)
        {
            foreach ($agerange_languages as $agerange)
            {
                $node->field_educational_typicalagerang['und'][0]['value'] = $agerange['value'];

                // Exit at first occurrence
                return $node;
            }
        }

        return $node;
    }

    private function createLifecycleContributeEntityField($node, $value)
    {
        foreach ($value as $author)
        {
            // Transformations
            $author = str_replace("\\", "", $author);

            $node->field_author_fullname['und'][0]['value'] = $author;

            return $node;
        }

        return $node;
    }

    private function createLifecycleContributeDateField($node, $value)
    {
        foreach ($value as $date)
        {
            $node->field_update_date['und'][0]['value'] = date('Y-m-d', strtotime($date['datetime']));

            return $node;
        }

        return $node;
    }

    private function createMetametadataContributeDateField($node, $value)
    {
        foreach ($value as $date)
        {
            $node->field_update_date['und'][0]['value'] = date('Y-m-d', strtotime($date['datetime']));

            return $node;
        }

        return $node;
    }

    private function createClassificationField($node, $value)
    {   
        foreach ($value as $classification)
        {
            foreach ($classification['taxonPath'] as $taxonPath)
            {
                foreach ($taxonPath['entry'] as $entry_languages)
                {
                    // Each language
                    foreach ($entry_languages as $entry)
                    {
                        $language = $entry['language'];
                        $language = $this->replaceWithHeuristics($language, "languages.ini");

                        // NOT MULTILINGUAL
                        $node->field_classification_taxonpath['und'][0]['value'] = $entry['value'];

                        return $node;
                    }
                }
            }
        }

        return $node;
    }

    private function createGeneralKeywordField($node, $value)
    {
        foreach ($value as $keyword_languages)
        {
            foreach($keyword_languages as $keyword)
            {
                // If keyword is blank...
                if (trim($keyword['value']) == "")
                    continue;

                $node->field_edu_tags['und'][]['tid'] = $this->getTermId($keyword['value'], 'edu_tags');
            }
        }

        return $node;
    }

    private function createGeneralAggregationlevelField($node, $value)
    {
        foreach ($value as $aggregation_level) {
            $aggregation_value = $this->replaceWithHeuristics($aggregation_level["value"], "aggregation_level.ini");

            $this->debug("AL: ". $aggregation_value, 5);
            exit(0);

            $node->field_field_aggregation_level = $this->getTermId($aggregation_value, 'aggregation_level');
        }

        return $node;
    }

    private function createTechnicalFormatField($node, $value)
    {
        // NOT IMPLEMENTED YET

        return $node;   
    }

    private function createDataRepositoryField($node, $value)
    {
        $value = $this->replaceWithHeuristics($value, "repositories.ini");

        $term_id = $this->getTermId($value, 'repository', false); 

        if ($term_id != 0)
            $node->field_data_provider['und'][]['tid'] = $term_id;

        return $node;
    }

    private function createEducationalContextField($node, $value)
    {
        foreach ($value as $educational_context)
        {   
            // If educational_context is blank...
            if (trim($educational_context['value']) == "")
                continue;

            $node->field_educational_context['und'][]['tid'] = $this->getTermId($educational_context['value'], 'educational_context');
        }

        return $node;   
    }

    /**
     * Check if FIELD_LO_IDENTIFIER exists or not in DB.
     * @param  string $lo_id
     * @return boolean
     */
    private function loIdentifierExists($lo_id)
    {
        $lo_identifier = db_query(
            "select entity_id from {field_data_field_lo_identifier} where field_lo_identifier_value = :lo_identifier limit 0,1", 
            array(
                ":lo_identifier" => $lo_id
            )
        )->fetchField();

        if(!$lo_identifier)
            return false;

        return true;
    }

    /**
     * Obtains a term id by specifying the term and a vocabulary, if term does
     * not exist, it will be created.
     * @param  string $term
     * @param  string $vocabulary
     * @return integer Term ID
     */
    private function getTermId($term, $vocabulary, $create_if_not_found = true)
    {
        // Get term on 'edu_tags' vocabulary
        $terms = taxonomy_get_term_by_name($term, $vocabulary);

        if(count($terms) > 0) {   
            // If term was found
            {
                $this->debug("Found term ID in vocabulary '". $vocabulary ."' with ID='". $key ."'.", 3);
                // Return ID of the tag
                return $key;
            }
        } else {
            // If create the term in the vocabulary
            if ($create_if_not_found) {
                $vid = taxonomy_vocabulary_machine_name_load($vocabulary)->vid;

                // Add term to the vocabulary
                taxonomy_term_save((object) array(
                  'name' => $term,
                  'vid' => $vid,
                ));

                $this->debug("Inserted new term '". $term ."' into vocabulary '". $vocabulary ."'.", 3);

                // Recursive ;)
                return $this->getTermId($term, $vocabulary);
            } else {
                // Return 0 if term not created
                return 0;
            }
        }
    }

    private function replaceWithHeuristics($value, $heuristics)
    {
        if (is_file($heuristics) && is_readable($heuristics)) {
            $heuristics = parse_ini_file($heuristics);

            if (array_key_exists($value, $heuristics)) {
                foreach ($heuristics as $heuristic => $replace) {
                    if ($heuristic == $value) {
                        $this->debug("String '". $value ."' has been replaced with '". $replace ."' with '". $heuristic ."'", 3);
                        $value = $replace;
                        break;
                    }
                }
            }
        }

        // MUST DEFINE STATIC TRANSFORMATIONS AS 'none'

        return $value;
    }
}

class ODSDocument
{
    // Where data is stored in
    private $data;

    // Stores the XML mapping
    private $map;

    // The XML as is
    private $xml;

    /**
     * Function that defines, according to Updater class functions, xpath
     * queries to get content from XMLs.
     */
    private function map()
    {
        return array(
            array(
                "name"  => "general_identifier",
                "xpath" => "/lom/general/identifier/entry",
                "type"  => "literal",
            ),
            array(
                "name"  => "general_language",
                "xpath" => "/lom/general/language",
                "type"  => "literal",
            ),
            array(
                "name"  => "general_title",            // array ML
                "xpath" => "/lom/general/title",
                "type"  => "multilingual",
            ),
            array(
                "name"  => "general_description",      // array ML
                "xpath" => "/lom/general/description",
                "type"  => "multilingual",
            ),
            array(
                "name"  => "general_keyword",          // array ML
                "xpath" => "/lom/general/keyword",
                "type"  => "multilingual",
            ),
            array(
                "name"  => "general_aggregationlevel", // array SV
                "xpath" => "/lom/general/aggregationLevel",
                "type"  => "sourcevalue",
            ),
            array(
                "name"  => "lifecycle_contribute_entity", // vcard
                "xpath" => "/lom/lifeCycle/contribute/entity",
                "type"  => "vcard",
            ),
            array(
                "name"  => "lifecycle_contribute_date",
                "xpath" => "/lom/lifeCycle/contribute/date",
                "type"  => "date",
            ),
            array(
                "name"  => "metametadata_contribute_date",
                "xpath" => "/lom/metaMetadata/contribute/date",
                "type"  => "date",
            ),
            array(
                "name"  => "technical_format",
                "xpath" => "/lom/technical/format",
                "type"  => "literal",
            ),
            array(
                "name"  => "technical_location",
                "xpath" => "/lom/technical/location",
                "type"  => "literal",
            ),
            array(
                "name"  => "educational_context",              // array SV
                "xpath" => "/lom/educational/context",
                "type"  => "sourcevalue",
            ),
            array(
                "name"  => "educational_typicalagerange",      // array ML
                "xpath" => "/lom/educational/typicalAgeRange",
                "type"  => "multilingual",
            ),
            array(
                "name"  => "rights_copyrightandotherrestrictions", // array SV
                "xpath" => "/lom/rights/copyrightAndOtherRestrictions",
                "type"  => "sourcevalue",
            ),
            array(
                "name"  => "classification",
                "xpath" => "/lom/classification",
                "type"  => "classification",
            ),
        );
    }

    public function ODSDocument($path)
    {

        // Read file
        $xml = simplexml_load_file($path);
        $plain_xml = $xml->asXML();
        unset($xml);

        // Gather repo
        $url_split = explode("/", $path);
        $this->data_repository = $url_split[1];

        echo "REPO: ". $this->data_repository ."\n";

        // Anti-prefix (namespace) hack
        $plain_xml = str_replace("lom:", "", $plain_xml);
        $plain_xml = preg_replace("/<lom (.*)\">(\s*)?<general>/i", "<lom><general>", $plain_xml);

        // Create XML operator
        $this->xml = new SimpleXMLElement($plain_xml);

        // Initialize field mapping
        $this->map = $this->map();

        // Magic starts here
        foreach ($this->map as $variable)
        {
            $this->{$variable["name"]} = $this->getContent($variable["xpath"], $variable["type"]);
        }
    }

    /**
     * Call dynamically a function that parses a pre-defined type of content
     * stored in the XML, such as Source-Value pairs.
     * 
     * @param  $xpath xpath query
     * @param  $type  type of content
     */
    private function getContent($xpath, $type)
    {
        return call_user_func(
            __NAMESPACE__ .'\ODSDocument::parse'. ucfirst($type) .'Element',
            $this->xml->xpath($xpath)
        ); 
    }

    /**
     * Don't blame my comments, this is magic...
     */
    public function __get($name)
    {
        if (!array_key_exists($name, $this->data)) {
            //this attribute is not defined!
            //throw new Exception('Variable not defined!');
            return null;
        } else {
            return $this->data[$name];
        } 

    }

    /**
     * Don't blame my comments, this is magic...
     */
    public function __set($name, $value){
        $this->data[$name] = $value;
    }

    /**
     * Function that parses a XPATH query result for a multilingual element
     * @param  array  $element
     */
    private function parseMultilingualElement(array $element)
    {
        // Array where we store mutilanguage content
        $store = array();

        // Each element ocurrence
        foreach ($element as $item)
        {
            $iteration = array();

            $single_element_xml = new SimpleXMLElement($item->asXML());

            // Each <string> ocurrence
            foreach ($single_element_xml as $string)
            {
                $string_store = array();

                $attributes = $string->attributes();

                $string_store["language"] = sprintf("%s", trim($attributes["language"]));
                $string_store["value"]    = sprintf("%s", trim($string));

                $iteration[] = $string_store;
            }

            $store[] = $iteration;  
        }
        
        return $store;
    }

    /**
     * Function that parses a XPATH query result for a classification element
     * @param  array  $element
     */
    private function parseClassificationElement(array $element)
    {
        $store = array();

        foreach ($element as $item)
        {
            $instance = array();

            $classification_xml = new SimpleXMLElement($item->asXML());

            // There is only one purpose
            $instance['purpose'] = $this->parseSourcevalueElement(
                $classification_xml->xpath("/classification/purpose")
            ); 

            $instance['taxonPath'] = $this->parseTaxonpathElement(
                $classification_xml->xpath("/classification/taxonPath")
            );

            $store[] = $instance;
        }

        return $store;
    }
    
    /**
     * Function that parses a XPATH query result for a taxonpath element
     * @param  array  $element
     */
    private function parseTaxonpathElement(array $element)
    {
        $store = array();

        foreach ($element as $item)
        {
            $instance = array();

            $taxonpath_xml = new SimpleXMLElement($item->asXML());

            $instance['source'] = $this->parseMultilingualElement(
                $taxonpath_xml->xpath("/taxonPath/source")
            );

            $instance['entry'] = $this->parseMultilingualElement(
                $taxonpath_xml->xpath("/taxonPath/taxon/entry")
            );

            $store[] = $instance;
        }

        return $store;
    }

    /**
     * Function that parses a XPATH query result for a literal element
     * @param  array  $element
     */
    private function parseLiteralElement(array $element)
    {
        $store = array();

        foreach ($element as $item)
        {
            $store[] = sprintf("%s", trim($item));
        }

        return $store;
    }

    /**
     * Function that parses a XPATH query result for a vCard element
     * @param  array  $element
     */
    private function parseVcardElement(array $element)
    {
        $store = array();

        foreach ($element as $item)
        {
            $matches = array();
            $vcard = sprintf("%s", $item);

            preg_match(
                "/FN:(.*)/", 
                $vcard, 
                $matches
            );

            if (count($matches) >= 2) {
                $store[] = trim($matches[1]);
            } else {
                $store[] = "";
            }
        }

        return $store;
    }

    /**
     * Function that parses a XPATH query result for a Source-Value pair
     * element
     * @param  array  $element
     */
    private function parseSourcevalueElement(array $element)
    {
        // Array where we store mutilanguage content
        $store = array();

        foreach ($element as $item)
        {
            $iteration = array();

            $iteration["source"] = sprintf("%s", trim($item->source));
            $iteration["value"]  = sprintf("%s", trim($item->value));

            $store[] = $iteration;
        }

        return $store;
    }

    /**
     * Function that parses a XPATH query result for a date element
     * @param  array  $element
     */
    private function parseDateElement(array $element)
    {
        $store = array();

        foreach ($element as $item)
        {
            $iteration = array();

            $iteration["datetime"]    = sprintf("%s", trim($item->dateTime));
            $iteration["description"] = sprintf("%s", trim($item->description));

            $store[] = $iteration;
        }

        return $store;
    }

    /**
     * Function that parses a XPATH query result for a duration element
     * @param  array  $element
     */
    private function parseDurationElement(array $element)
    {
        $store = array();

        foreach ($element as $item)
        {
            $iteration = array();

            $iteration["duration"]    = sprintf("%s", trim($item->durationTime));
            $iteration["description"] = sprintf("%s", trim($item->description));

            $store[] = $iteration;
        }

        return $store;
    }

    /**
     * Get captured data
     * @return mixed captured data
     */
    public function getData()
    {
        return $this->data;
    }
}


// BON APETIT!
echo "\n\n\n\n\n\n\n\n";
echo "OPEN DISCOVERY SPACE - DRUPAL UPDATER\n";
echo "=====================================\n\n";

echo "Generating update tree...\n";
$files = directoryToArray("harvest", true);

echo "Cleaning tree...\n";
foreach($files as $key => $file)
{
    if(is_dir($file))
        unset($files[$key]);

    // Discard non-xml files
    if (strlen($file) > 4) 
        if (substr($file, -4) != ".xml") {
            unset($files[$key]);
        }
}

echo "Instance updater...\n";
$updater = new Updater();

$processed_files = 0;

echo "Processing XML...\n";
foreach ($files as $file)
{
    echo "> '". $file ."'...\n";
    $doc = new ODSDocument($file);

    $updater->createNode($doc->getData());

    $processed_files++;
}

echo "\n\n\n\nUPDATER HAS FINISHED\n";
echo "PROCESSED FILES: ". $processed_files ."\n";