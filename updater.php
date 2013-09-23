<?php

//
// Updater version: 13.9.21
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

$GLOBALS['heuristics']         = array();
$GLOBALS['updater_path']       = variable_get('ods_updater_xml_root_file_path', 'DEFAULT_PATH');
$GLOBALS['updater_path_new']   = '/new';
$GLOBALS['updater_path_old']   = '/old';
$GLOBALS['updater_path_error'] = '/error';
$GLOBALS['actual_resource']    = 'none'; 
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

function create_dir($dir) {
    exec("mkdir -p ". $dir);
}

function fromNew2old($path) {
    $path = "/".$path;
    $new = $GLOBALS['updater_path'].$GLOBALS['updater_path_new'].$path;
    $old = $GLOBALS['updater_path'].$GLOBALS['updater_path_old'].$path;

    /*echo "\nDIR: ". dirname($old);
    echo "\nNEW: ". $new;
    echo "\nOLD: ". $old;*/

    create_dir(dirname($old));

    rename($new, $old);
}

function fromNew2error($path) {
    $path = "/".$path;
    $new   = $GLOBALS['updater_path'].$GLOBALS['updater_path_new'].$path;
    $error = $GLOBALS['updater_path'].$GLOBALS['updater_path_error'].$path;

    /*echo "\nDIR: ". dirname($error);
    echo "\nNEW: ". $new;
    echo "\nERROR: ". $error;*/

    create_dir(dirname($error));

    rename($new, $error);
}

function getDataRepo($path) {
    $url_split = explode("/", $path);   
    $length = count($url_split);
    return $url_split[$length-2];
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

        // UPDATE NODE IF EXISTING
        $lo_id = $node->field_lo_identifier['und'][0]['value'];
        $node_exists = $this->loIdentifierExists($lo_id);

        if (is_numeric($node_exists)) {
            $this->updateNode($node, $node_exists);
        } else {
            $this->saveNode($node);
        }
    } 

    /**
     * Updates a node 
     * @param  stdClass $new_node         new node created with new information
     * @param  int $existing_node_id nid of the existing node with the same information
     */
    private function updateNode($new_node, $existing_node_id)
    {
        $this->debug("Updating node...");

        $this->debug("Loading existing node...", 2);
        $node = node_load($existing_node_id);
        
        $this->debug("Updating info...", 2);
        $update_time = new DateTime('NOW');

        $new_node->nid                    = $node->nid;
        $new_node->vid                    = $node->vid;
        $new_node->log                    = $node->log;
        $new_node->status                 = $node->status;
        $new_node->comment                = $node->comment;
        $new_node->promote                = $node->promote;
        $new_node->sticky                 = $node->sticky;
        $new_node->created                = $node->created;
        $new_node->changed                = $update_time->format('U');
        $new_node->tnid                   = $node->tnid;
        $new_node->translate              = $node->translate;
        $new_node->revision_timestamp     = $node->revision_timestamp;
        $new_node->revision_uid           = $node->revision_uid;
        $new_node->cid                    = $node->cid;
        $new_node->last_comment_timestamp = $node->last_comment_timestamp;
        $new_node->last_comment_name      = $node->last_comment_name;
        $new_node->last_comment_uid       = $node->last_comment_uid;
        $new_node->comment_count          = $node->comment_count;
        $new_node->picture                = $node->picture;
        $new_node->name                   = $node->name;
        $new_node->data                   = $node->data;
        $new_node->uid                    = $node->uid;

        $this->debug("Saveing node '". $new_node->nid ."'...", 2);
        node_save($new_node);
        $this->debug(date("h:i:s: ")."Saved node '". $new_node->nid ."'...", 2);
    }

    /**
     * Saves a node
     * @param  stdClass $node
     */
    private function saveNode($node)
    {   
        try {
            $this->debug("Saving node...");

            // PREVENT GHOST NODES
            $has_title      = trim($node->title) != "";
            $has_identifier = trim($node->field_lo_identifier['und'][0]['value']) != "";
            $has_location   = trim($node->field_eo_link['und'][0]['url']) != "";


            if($has_title && $has_identifier && $has_location) {
                if($node = node_submit($node)) {
                    node_save($node);

                    $this->debug(date("h:i:s: ").": Saving node '".$node->nid."' finished!", 2);
                    $GLOBALS['actual_resource'] = 'success';
                }   
            }
        } catch (Exception $e) {
            $this->debug("Node couldn't be saved!!", 2);
            $GLOBALS['actual_resource'] = 'error';
        }
        
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

            //$node->language = $language;

            $term_id = $this->getTermId($language, 'ods_ap_languages', false);

            if ($term_id !== false) {
                $node->field_general_language['und'][]['tid'] = $term_id;
            } else {
                $this->debug("Bad language ".$value.".");
            }
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
                    $node->title = $this->ensureLength255($title['value']);
                }

                // Set current language translation
                $node->title_field[$language][0]['value'] = $this->ensureLength255($title['value']);
            }

            // If node title has been not set, set it now
            if ($node->title == "") {
                if (is_array($title_languages) && count($title_languages) > 0)
                    $node->title = $this->ensureLength255($title_languages[0]['value']);
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

                $node->field_eo_description[$language][0]['value']   = $description['value'];
                //$node->field_eo_description[$language][0]['summary'] = $description['value'];
                $node->field_eo_description[$language][0]['format']  = 'filtered_html';

                if($is_first) {
                    // UND BY DEFAULT
                    $node->field_eo_description['und'][0]['value']   = $description['value'];
                    //$node->field_eo_description['und'][0]['summary'] = $description['value'];
                    $node->field_eo_description['und'][0]['format']  = 'filtered_html';

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
            $node->field_eo_link['und'][0]['url'] = $technical_location;
            $node->field_eo_link['und'][0]['title'] = "View Resource";

            // Return at first occurrence (only one/first technical location is supposed to be shown)
            return $node;
        }

        return $node;
    }

    private function createRightsCopyrightandotherrestrictionsField($node, $value)
    {
        foreach ($value as $copyright)
        {
            $copyright_value = (strlen($copyright['source']) > 0) ? "Yes" : "No";

            $term_id = $this->getTermId($copyright_value, 'ods_ap_rights_copyright', false);
            
            if ($term_id !== false)
                $node->field_rights_copyright['und'][]['tid'] = $term_id;
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
            // Clean vCard
            $matches = array();
            $vcard = sprintf("%s", $author);

            preg_match(
                "/FN:(.*)/", 
                $vcard, 
                $matches
            );

            if (count($matches) >= 2) {
                $node->field_author_fullname['und'][0]['value'] = trim($matches[1]);
            } else {
                $node->field_author_fullname['und'][0]['value'] = "";
            }

            // Transformations
            $node->field_author_fullname['und'][0]['value'] = str_replace("\\", "", $node->field_author_fullname['und'][0]['value']);

            if ($node->field_author_fullname['und'][0]['value'] == "") {
                // If not vCard, just put RAW data
                $node->field_author_fullname['und'][0]['value'] = $author;
            }

            $this->debug("Author detected as: '". $node->field_author_fullname['und'][0]['value'] ."'", 5);

            return $node;
        }

        return $node;
    }

    private function createLifecycleContributeDateField($node, $value)
    {
        foreach ($value as $date)
        {
            $node->field_eo_update_date['und'][0]['value'] = new DateTime($date['datetime']);//date('Y-m-d', strtotime($date['datetime']));

            $year_str = $node->field_eo_update_date['und'][0]['value']->format('Y');
            $year_int = intval($year_str);

            if ($year_int < 1990) {
                $this->debug("Date under 1990! setting it back to the 90's!");
                $node->field_eo_update_date['und'][0]['value'] = '1990-01-01';
            } else {
                $node->field_eo_update_date['und'][0]['value'] = $node->field_eo_update_date['und'][0]['value']->format('Y-m-d');
            }

            return $node;
        }

        return $node;
    }

    private function createMetametadataContributeDateField($node, $value)
    {
        foreach ($value as $date)
        {
            $node->field_eo_update_date['und'][0]['value'] = new DateTime($date['datetime']);//date('Y-m-d', strtotime($date['datetime']));

            $year_str = $node->field_eo_update_date['und'][0]['value']->format('Y');
            $year_int = intval($year_str);

            if ($year_int < 1990) {
                $this->debug("Date under 1990! setting it back to the 90's!");
                $node->field_eo_update_date['und'][0]['value'] = '1990-01-01';
            } else {
                $node->field_eo_update_date['und'][0]['value'] = $node->field_eo_update_date['und'][0]['value']->format('Y-m-d');
            }

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
                        $node->field_classification_taxonpath['und'][0]['value'] = $this->ensureLength255($entry['value']);

                        // Classification discipline
                        if (strpos($entry['value'], ':')) {
                            // Word with separators

                            $entry_value = str_replace("::", ":", $entry['value']);
                            $exploded = explode(':', $entry_value);

                            $last = count($exploded) - 1;
                            $classification_discipline = trim($exploded[$last]);

                            $term_id = $this->getTermId($classification_discipline, 'ods_ap_classification_discipline', false);
                            $this->debug("Discipline detected as: '". $classification_discipline ."'", 5);
                            if ($term_id !== false)
                                $node->field_classification_discipline['und'][]['tid'] = $term_id;
                        } else {
                            // Single word

                            $term_id = $this->getTermId($node->field_classification_taxonpath['und'][0]['value'], 'ods_ap_classification_discipline', false);
                            $this->debug("Discipline detected as: '". $node->field_classification_taxonpath['und'][0]['value'] ."'", 5);
                            if ($term_id !== false)
                                $node->field_classification_discipline['und'][]['tid'] = $term_id;
                        }

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

                $term_id = $this->getTermId($keyword['value'], 'edu_tags');

                if ($term_id !== false)
                    $node->field_edu_tags['und'][]['tid'] = $term_id;
            }
        }

        return $node;
    }

    private function createGeneralAggregationlevelField($node, $value)
    {
        foreach ($value as $aggregation_level) {
            $aggregation_value = $this->replaceWithHeuristics($aggregation_level["value"], "aggregation_level.ini");

            $term_id = $this->getTermId($aggregation_value, 'aggregation_level', false);

            if ($term_id !== false)
                $node->field_aggregation_level['und'][]['tid'] = $term_id;
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

        $this->debug("Data provider: ". $value, 3);

        if ($term_id !== false) {
            // Evade wrong repository
            if (strpos($value,'xml') !== false) {
                $this->debug("--------- DETECTED WRONG REPOSITORY --------", 5);
                return $node;
            }

            $node->field_data_provider['und'][]['tid'] = $term_id;
        }

        return $node;
    }

    private function createXmlPathField($node, $value)
    {
        $simple_path = getDataRepo($value) ."/". basename($value);

        $node->field_xml_path = $simple_path;

        return $node;
    }

    private function createEducationalContextField($node, $value)
    {
        foreach ($value as $educational_context)
        {   
            // If educational_context is blank...
            if (trim($educational_context['value']) == "")
                continue;

            $term_id = $this->getTermId($educational_context['value'], 'educational_context');

            if ($term_id !== false)
                $node->field_educational_context['und'][]['tid'] = $term_id;
        }

        return $node;   
    }

    /**
     * Check if FIELD_LO_IDENTIFIER exists or not in DB.
     * @param  string $lo_id
     * @return boolean or lo_id
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

        return $lo_identifier;
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
            foreach($terms as $key => $value)
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
                // Return false if term not created
                return false;
            }
        }
    }

    private function replaceWithHeuristics($value, $heuristics_file)
    {
        // If heuristics not loaded
        if (!array_key_exists($heuristics_file, $GLOBALS['heuristics'])) {
            // Load heuristics
            if (is_file($heuristics_file) && is_readable($heuristics_file)) {
                $heuristics = parse_ini_file($heuristics_file);
                $GLOBALS['heuristics'][$heuristics_file] = $heuristics; 

                // Run heuristics
                return $this->replaceWithHeuristics($value, $heuristics_file);
            }
        } else {
            //If heuristics loaded
            $heuristics = $GLOBALS['heuristics'][$heuristics_file];

            if (array_key_exists($value, $heuristics)) {
                foreach ($heuristics as $heuristic => $replace) {
                    if ($heuristic == $value) {
                        $this->debug("String '". $value ."' has been replaced with '". $replace ."' with '". $heuristics_file ."'", 3);

                        $value = $replace;
                        break;
                    }
                }
            }

            // MUST DEFINE STATIC TRANSFORMATIONS AS 'none'

            return $value;
        }

    }

    private function ensureLength255($string)
    {
        if (strlen($string) > 254) {
            $string = substr($string, 0, 254);
        }

        return $string;
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
                "type"  => "literal",//"vcard",
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
        //$url_split = explode("/", $path);
        $this->data_repository = getDataRepo($path);//$url_split[1];
        $this->xml_path        = getDataRepo($path) ."/". basename($path);

        $this->debug("Repository is set to '". $this->data_repository ."'", 2);

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
$files = directoryToArray($GLOBALS['updater_path'].$GLOBALS['updater_path_new'], true);

echo "Cleaning tree...\n";
foreach($files as $key => $file)
{
    if(is_dir($file))
        unset($files[$key]);

    // Discard non-xml files
    if (strlen($file) > 4) 
        if (substr($file, -4) != ".xml") {
            unset($files[$key]);
            continue;
        }

    if (strpos($file,'%') !== false) {
        unset($files[$key]);
        continue;
    }
}

echo "Instance updater...\n";
$updater = new Updater();

$processed_files = 0;

echo "Processing XML...\n";
$size = sizeof($files);
$fullStartTime = microtime(true);
$meanDuration = 0.0;
foreach ($files as $file)
{
    $GLOBALS['actual_resource'] = 'none';
    $simple_path = getDataRepo($file) ."/". basename($file);

    $startTime= microtime(true);

    echo "> '". $file ."'...\n";
    $doc = new ODSDocument($file);

    $updater->createNode($doc->getData());

    
    switch($GLOBALS['actual_resource']) 
    {
        case 'success':
            fromNew2old($simple_path);
            break;
        case 'error':
            fromNew2error($simple_path);
            break;
        default:
            // Nothing
            break;
    }

    $duration = (microtime(true)-$startTime);
    $meanDuration = ($processed_files*$meanDuration + $duration)/($processed_files+1.0); // in s
    $leftTime = (($size-$processed_files)*$meanDuration);
    $leftTimeHours = intval($leftTime/3600);
    $leftTimeS = strval($leftTimeHours).":".strval(intval(($leftTime-$leftTimeHours*3600)/60));
    echo "> Processed \"".$file."\" in $duration ms ($processed_files of $size, estimate time left $leftTimeS)\n";

    $processed_files++;
}

echo "\n\n\n\nUPDATER HAS FINISHED\n";
echo "PROCESSED FILES: ". $processed_files ."\n";