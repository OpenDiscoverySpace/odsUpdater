<?php

//
// Updater version: 14.0.5
//
// Copyright (c) 2014 UAH
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
// 
// 

define('UPDATER_DEBUG_ENABLED', true);

$GLOBALS['heuristics'] = array();
$GLOBALS['updater_path'] = variable_get('ods_updater_xml_root_file_path', 'DEFAULT_PATH'); //home/odssearch/bigData
$GLOBALS['updater_path_new'] = '/new';
$GLOBALS['updater_path_old'] = '/old';
$GLOBALS['updater_path_error'] = '/error';
$GLOBALS['UND_LANG_CODE'] = 'en'; //According to the code assigned to the 'und' language code in the language_codes.ini file.
$GLOBALS['rep_cnt_missing_titles'] = array(); //Array with the number of missing titles in each repository.

// RUN THE UPDATER
echo "\n\n\n\n\n\n\n\n";
echo "=====================================\n";
echo "OPEN DISCOVERY SPACE - DRUPAL UPDATER\n";
echo "=====================================\n\n";

echo "Generating the updater tree...\n";
$files = directoryToArray($GLOBALS['updater_path'].$GLOBALS['updater_path_new'], true);
echo "Total elements in the updater XML root file path: " . count($files) ."\n";
foreach ($files as $key => $file) {
    echo "<" . ($key + 1) . "> " . $file . "\n";
}

$num_invalid_files = 0;
$num_repositories = 0;

//Create the log file that contains information about the state of each file: 
//error file, added as a node and updated a node.
$log_ods_files = fopen($GLOBALS['updater_path'].$GLOBALS['updater_path_new'].date("/d-m-Y_H:i:s_")."updater.log", "a") or die("Problems in the creation");

//Create the output directories is they don't exist
$old_folder = $GLOBALS['updater_path'].$GLOBALS['updater_path_old']; 
if(!file_exists($old_folder))
{
    mkdir ($old_folder);
} 
$error_folder = $GLOBALS['updater_path'].$GLOBALS['updater_path_error']; 
if(!file_exists($error_folder))
{
    mkdir ($error_folder);
} 

//REMOVE NON-XML FILES
echo "Cleaning the tree...\n";
foreach($files as $key => $file)
{
    if(is_dir($file)){
        //Sample output: /home/odssearch/bigData/new/ORGANIC_EDUNET
        //The element is a directory, therefore the last folder is a repository
        //(the name of the folder represents the name of the repository).
        $last_pos_file_separator = strrpos($file, '/');
        $rp_name = substr($file, $last_pos_file_separator+1, strlen($file));
        $GLOBALS['rep_cnt_missing_titles'][$rp_name] = 0;
        unset($files[$key]);
        $num_repositories++;
        $num_invalid_files++;
    } elseif (strlen($file) > 4 and substr($file, -4) != ".xml") {
        //The element doesn't have the .xml extension.
        unset($files[$key]);
        $num_invalid_files++;
        //Include the file name in the log file.
        fputs($log_ods_files, "> " . $file . " doesn't represent a xml file." . "\n");
        //Move to the error folder
        fromNewToError($file);
    } elseif (strpos($file,'%') !== false) {
        //The element includes the % symbol.
        unset($files[$key]);
        $num_invalid_files++;
        //Include the file name in the log file.
        fputs($log_ods_files, "> " . $file . " doesn't represent a xml file." . "\n");
        //Move to the error folder
        fromNewToError($file);
    }
}

//PROCESS XML FILES
$num_processed_files = 0;
$num_updated_nodes = 0;
$num_new_nodes = 0;

echo "Processing XML files...\n\n";
echo "Creating the instance of the Updater...\n\n";
$size = sizeof($files);
foreach ($files as $file)
{
    echo ">>>File to be processed: " . $file . "\n";

    //Obtain the content of the XML file using the PHP function file_get_contents:
    $xml = file_get_contents($file);
    //print_r($xml);
    //We use DOM to process the files using the PHP function DOMDocument:
    //The DOM extension allows us to operate on XML documents through the DOM API with PHP 5.
    $DOM = new DOMDocument('1.0', 'utf-8');
    $DOM->loadXML($xml);
    //Obtain the historic folder.
    $newPathFile = getHistoricPathFile($file);
    //Read the information from the XML file.
    $ods_node = new ODSNode($DOM, getRepositoryName($file), $newPathFile);
    $ods_node->extractInfoFromXML();
    //We discard the file if it doesn't contain the ODS identifiers
    $generalID = $ods_node->getODSGeneralIdentifier();
    $metadataID = $ods_node->getODSMetadataIdentifier();
    if (empty($generalID) and empty($metadataID)){
        echo "The file is discarded because it doesn't contain the ODS identifiers.\n\n";
        $num_invalid_files++;
        //Include the file name in the log file.
        fputs($log_ods_files, "> " . $file . " discarded because it doesn't contain the ODS identifiers.\n");
        //Move to the error folder.
        fromNewToError($file);
    } else {
        try{

            //Create the instance of the Updater class in order to process the node.
            $updater = new Updater($ods_node);
            //Check if the node is in Drupal. If we find the same node, 
            //then we have to replace the information that 
            //is stored in the Drupal database with the new information.    
            $node_id = $updater->checkNode();
            if ($node_id > 0){
                //The node exists in the Drupal portal.
                //We have to update the node.
                $updater->updateNode($node_id);
                $num_updated_nodes++;
                //Include the file name in the log file.
                fputs($log_ods_files, "> " . $file . " updated a node.\n");
                //Move to the old folder.
                fromNewToOld($file);
            }else{        
                //Create the node in Drupal for this file
                $updater->addNode();
                $num_new_nodes++;
                //Include the file name in the log file.
                fputs($log_ods_files, "> " . $file . " added as a node.\n");
                //Move to the old folder.
                fromNewToOld($file);
            }
            $num_processed_files++;
        }catch (XMLFileException $e) {
            //Display the custom message
            echo $e->errorMessage();
            $num_invalid_files++;
            //Include the error in the log file.
            fputs($log_ods_files, "> " . $file ." discarded because: " . $e->errorMessage());
            //Move to the error folder.
            fromNewToError($file);
        }catch (UpdateNodeException $e){            
            //Display the custom message
            echo $e->errorMessage();
            $num_invalid_files++;
            //Include the error in the log file.
            fputs($log_ods_files, "> " . $file ." didn't update the node because: " . $e->errorMessage());
            //Move to the error folder.
            fromNewToError($file);
        }catch (AddNodeException $e){            
            //Display the custom message
            echo $e->errorMessage();
            $num_invalid_files++;
            //Include the error in the log file.
            fputs($log_ods_files, "> " . $file ." didn't add the node because: " . $e->errorMessage());
            //Move to the error folder.
            fromNewToError($file);
        }
    }
}

echo "\n-------\n";
fputs($log_ods_files, "\n-------\n");
echo "SUMMARY\n";
fputs($log_ods_files, "SUMMARY\n");
echo "-------\n";
fputs($log_ods_files, "-------\n");
echo "Repositories: ". $num_repositories ."\n";
fputs($log_ods_files, "Repositories: ". $num_repositories ."\n");
echo "Valid XML files: ". $num_processed_files ."\n";
fputs($log_ods_files, "Valid XML files: ". $num_processed_files ."\n");
echo "Invalid XML files: ". $num_invalid_files ."\n";
fputs($log_ods_files, "Invalid XML files: ". $num_invalid_files ."\n");
echo "New nodes: ". $num_new_nodes ."\n";
fputs($log_ods_files, "New nodes: ". $num_new_nodes ."\n");
echo "Updated nodes: " . $num_updated_nodes ."\n";
fputs($log_ods_files, "Updated nodes: " . $num_updated_nodes ."\n");
echo "Number of files with missing titles in each repository:\n";
fputs($log_ods_files, "Number of files with missing titles in each repository:\n");
foreach ($GLOBALS['rep_cnt_missing_titles'] as $key => $cnt) {
    echo "--> Repository " . $key . ": " . $cnt . "\n";
    fputs($log_ods_files, "--> Repository " . $key . ": " . $GLOBALS['rep_cnt_missing_titles'][$key] . "\n");
}
echo "\n\nUPDATER PROCESS COMPLETE!\n\n";

//Close the logfile;
fclose($log_ods_files);
//Remove empty folders from the /new folder (when you rename the files the files
//are moved to the new location, but the folder still are in the new folder)
$files = directoryToArray($GLOBALS['updater_path'].$GLOBALS['updater_path_new'], true);
foreach($files as $file)
{
    if(is_dir($file)){
        //Sample output: /home/odssearch/bigData/new/ORGANIC_EDUNET
        //The element is a directory, therefore we have to remove it.
        rmdir($file);
    }
} 

/**
 * This functions reads a directory (in our case home/odssearch/bigData/new) and subdirectories and stores them as an array.
 * Each element in the array is in the form like: /home/odssearch/bigData/new/OESamples/example3.xml
 * The subfolders are also stored in the array, for example: /home/odssearch/bigData/new/OESamples
 * @param  string $directory
 * @param  boolean $recursive
 * @return array with the elements of the directory.
 */
function directoryToArray($directory, $recursive) 
{
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
}//End function directoryToArray


/**
* This function obtain the new location of a processed file.
* @param String with the complete path of the file.
* @return The new location of the file.
*/
function getHistoricPathFile($file) {

    $path = getPathToSave($file);
    $old_file = $GLOBALS['updater_path'].$GLOBALS['updater_path_old'].$path;

    return $old_file;
}//End function getHistoricPath

/**
* This function obtain the location to save in the historic folder for the
* XML files that have been processed.
* @param String with the complete path of the file.
* @return The final path to save for the file.
*/
function getPathToSave($file){

    $last_pos = strripos($file, "/");
    $file_name = substr($file, $last_pos, strlen($file));
    $repo_name = getRepositoryName($file);
    $path = "/". $repo_name . $file_name;
    
    return $path;
}//End function getPathToSave


/**
* This function move the processed file to the old folder in order to recover it later
* for the metadata viewer in the summary page (search).
* @param String with the complete path of the file.
*/
function fromNewToOld($file) {

    //Create repository folder 
    $repo_name = getRepositoryName($file);
    $old_folder = $GLOBALS['updater_path'].$GLOBALS['updater_path_old']; 
    $repo_folder = $old_folder . "/" . $repo_name;
    if(!file_exists($repo_folder))
    {
        mkdir ($repo_folder);
    } 
    $path = getPathToSave($file);
    $new_file = $GLOBALS['updater_path'].$GLOBALS['updater_path_new'].$path;
    $old_file = $GLOBALS['updater_path'].$GLOBALS['updater_path_old'].$path;

    //Move the file to the new location.
    rename($new_file, $old_file);
}//End function fromNewToOld

/**
* This function move the invalid file to the error folder.
* @param String with the complete path of the file.
*/
function fromNewToError($file) {

    $last_pos = strripos($file, "/");
    $file_name = substr($file, $last_pos, strlen($file));
    //Create repository folder 
    $repo_name = getRepositoryName($file);
    if (strcmp($repo_name, "new") == 0){
        //It is not contained in a repository, it could be a previous log file.
        $path = $file_name;
    } else {
        $error_folder = $GLOBALS['updater_path'].$GLOBALS['updater_path_error']; 
        $repo_folder = $error_folder . "/" . $repo_name;
        if(!file_exists($repo_folder))
        {
            mkdir ($repo_folder);
        } 
        $path = getPathToSave($file);
    }

    $new_file   = $GLOBALS['updater_path'].$GLOBALS['updater_path_new'].$path;
    $error_file = $GLOBALS['updater_path'].$GLOBALS['updater_path_error'].$path;

    //Move the file to the new location.
    rename($new_file, $error_file);
}//End function fromNewToError

/**
* Returns the name of the parent folder of a path file that represents the repository of a resource. 
* @param string $path (e.g. /home/odssearch/bigData/new/OESamples/example3.xml)
* @return string with the name of the repository (Using the previous example it will return OESamples)
*/
function getRepositoryName($path) {
    $url_split = explode("/", $path); 
    $length = count($url_split);
    return $url_split[$length-2];
}//End function getRepositoryName


/**
* This class contains the information that has to be stored in the Drupal node of type Educational Object.
* The class extracts all the required information from the DOM document that receives
* in the constructor and it stores the repository name.
*/
class ODSNode
{
    private $ods_dom_document;
    private $ods_data_provider;
    private $ods_lo_identifiers;
    private $ods_general_identifier;
    private $ods_titles;
    private $ods_languages;
    private $ods_descriptions;
    private $ods_keywords;
    private $ods_aggregation_level;
    private $ods_lifecycle_contributes;
    private $ods_metadata_identifier;
    private $ods_resource_links;
    private $ods_educationals;
    private $ods_copyright;
    private $ods_cost;
    private $ods_classifications;
    private $ods_file_location;
    private $ods_technical_formats;
    
    /**
    * @param DOMDocument $dom_doc. The object that represents the XML document.
    * @param String $repo_name. The name of the repository that includes this XML document.
    */

    public function __construct ($dom_doc, $repo_name, $file_path)
    {
        //Initialize class properties.
        $this->ods_dom_document = $dom_doc;
        $this->ods_data_provider = $repo_name;
        $this->ods_lo_identifiers = array();
        $this->ods_general_identifier = "";
        $this->ods_titles = array();
        $this->ods_languages = array();
        $this->ods_descriptions = array();
        $this->ods_keywords = array();
        $this->ods_aggregation_level = 1; //educational object.
        $this->ods_lifecycle_contributes = array();
        $this->ods_metadata_identifier = "";
        $this->ods_resource_links = array();
        $this->ods_educationals = array();
        $this->ods_copyright = ""; //posible values: yes or no.
        $this->ods_cost = ""; //posible values: yes or no.
        $this->ods_classifications = array();
        $this->ods_file_location = $file_path;
        $this->ods_technical_formats = array();

    }//End function __construct

    //----------------------------------------
    // CLASS PROPERTIES
    //----------------------------------------

    public function getODSDataProvider(){
        return $this->ods_data_provider;
    }

    public function getODSloIdentifiers(){
        return $this->ods_lo_identifiers;
    }

    public function getODSGeneralIdentifier(){
        return $this->ods_general_identifier;
    }

    public function getODSTitles(){
        return $this->ods_titles;
    }

    public function getODSLanguages(){
        return $this->ods_languages;
    }

    public function getODSDescriptions(){
        return $this->ods_descriptions;
    }

    public function getODSKeywords(){
        return $this->ods_keywords;
    }

    public function getODSAggregationLevel(){
        return $this->ods_aggregation_level;
    }

    public function getODSLifeCycleContributes(){
        return $this->ods_lifecycle_contributes;
    }

    public function getODSMetadataIdentifier(){
        return $this->ods_metadata_identifier;
    }

    public function getODSResourceLinks(){
        return $this->ods_resource_links;
    }

    public function getODSEducationals(){
        return $this->ods_educationals;
    }

    public function getODSCopyright(){
        return $this->ods_copyright;
    }

    public function getODSCost(){
        return $this->ods_cost;
    }

    public function getODSClassifications(){
        return $this->ods_classifications;
    }

    public function getODSFileLocation(){
        return $this->ods_file_location;
    }

    public function getODSTechnicalFormat(){
        return $this->ods_technical_formats;
    }

    //----------------------------------------
    // METHODS
    //----------------------------------------

    /**
    * This function reads the dom document that receives as input and stores the information that
    * is need for the updater in order to create de Drupal Educational Object nodes.
    * It serves as the root of the document tree.
    */
    public function extractInfoFromXML ()
    {
        $this->getGeneralCategoryInfo();
        $this->getLifecycleCategoryInfo();
        $this->getMetaMetaDataInfo();
        $this->getTechnicalCategoryInfo();
        $this->getEducationalCategoryInfo();
        $this->getRightsCategoryInfo();
        $this->getClassificationCategoryInfo();

    }//End function extractInfoFromXML

    /**
    * This function extracts the required information from the General label.
    */
    private function getGeneralCategoryInfo ()
    {        

        //GENERAL
        //DOMNodeList $general
        $general = $this->ods_dom_document->getElementsByTagName('general');
        //The number of general elements should be always 1 as minimum and maximum.
        if ($general->length > 0){

            //General identifiers
            //<identifier>
            //DOMNodeList $identifiers
            $identifiers = $general->item(0)-> getElementsByTagName("identifier");
            //The number of Identifier labels could be more than 1.
            //If the number is 0 this loop will not execute.
            foreach ($identifiers as $id) {
                //<catalog>
                //DOMNodelList $catalogs
                $catalogs = $id->getElementsByTagName("catalog");
                $catalog = $catalogs->item(0)->nodeValue;
                //<entry>
                //DOMNodeList $entries
                $entries = $id->getElementsByTagName("entry");
                $entry = $entries->item(0)->nodeValue;
                if (strcmp($catalog, "ODS") == 0){
                    $this->ods_general_identifier = $entry;
                } else {
                    //Add the identifier to the general identifiers array.
                    $this->ods_lo_identifiers[] = $entry; 
                    //Reindex the array to the new number of identifiers in the array.
                    //$this->ods_lo_identifiers = array_values($this->ods_lo_identifiers);
                }
            }

            //Title
            //<title>
            //DOMNodeList $titles
            $titles = $general->item(0)-> getElementsByTagName("title");
            //The number of Title labels should be always 1.
            if ($titles->length > 0){
                //Each title can have several language strings.
                //<string>
                $this->ods_titles = $this->getLangStrings($titles->item(0));
            }

            //Language
            //<language>
            //DOMNodeList $languages
            $languages = $general->item(0)-> getElementsByTagName("language");
            //The number of Language labels could be 0 or more than 1.
            foreach ($languages as $key => $lang) {
                //Add the identifier to the general identifiers array.
                //We have to check that we don't have something like that: <language />
                if (!empty($lang->nodeValue)){
                    if (strcmp($lang->nodeValue, "none" == 0)) {
                        //If the language is none, we change it with the code of the
                        //undefined language.
                        $this->ods_languages[] = "und"; 
                        //Reindex the array to the new number of languages in the array.
                        $this->ods_languages = array_values($this->ods_languages);

                    }else {                        
                        $this->ods_languages[] = $lang->nodeValue; 
                        //Reindex the array to the new number of languages in the array.
                        $this->ods_languages = array_values($this->ods_languages);
                    }        
                }
            }

            //Description
            //<description>
            //DOMNodeList $descriptions
            $descriptions = $general->item(0)-> getElementsByTagName("description");
            //The number of Description labels could be 0 or more than 1.
            foreach ($descriptions as $desc) {
                //Each description can have several language strings.
                //<string>
                //Add the new description array (it has several lang strings) to the ods descriptions array:
                $this->ods_descriptions[] = $this->getLangStrings ($desc); 
            }

            //Keyword
            //<keyword>
            //DOMNodeList $keywords
            $keywords = $general->item(0)-> getElementsByTagName("keyword");
            //The number of Keyword labels could be 0 or more than 1.
            foreach ($keywords as $word) {
                //Each keyword can have several language strings.
                //<string>
                //Add the new keyword array (it has several lang strings) to the ods keywords array:
                $this->ods_keywords[] = $this->getLangStrings ($word); 
            }

            //Aggregation level
            //<aggregationlevel>
            //Not present in the xml samples files. It always will have the value of 1 (educational object)
            $this->ods_aggregation_level = 1; //1: educational object.

        }
    }//End function getGeneralCategoryInfo


    /**
    * This function read from the XML file all the <string> labels.
    * @param string $node The node that contains the <string> labels.
    * @return The array with the different strings where the index of the array is the language code.
    */
    private function getLangStrings ($dom_element){

        $aux_list = array();
        //<string>
        //DOMNodeList $strings
        $strings = $dom_element->getElementsByTagName("string");
        //The number of String labels could be 0 or more than 1.
        foreach ($strings as $str) {
            //Get the language attribute of the string label.
            //<string language = "xx">
            $lang_word = $str->getAttribute("language");
            //We should discard the elements that don't have this attribute, because
            //according to the LOM standard these elements are LangString. However
            //in the ODS project we process it, so we will assign the "und" value
            //in order to indicate that it has an undefined language.
            //However, if we have more than one string with no language, only the last one
            //will be stored in the 'und' position .
            //We have to control also that we have a value in the node and not something
            //like that: <string language="en" />.
            if (!empty($lang_word) and !empty($str->nodeValue)) {
                if (strcmp($lang_word, "none") == 0){
                    //If the language is "none" we change it with the code of the 
                    //undefined language.
                    $aux_list[] = new LangString('und', $str->nodeValue);
                } else $aux_list[] = new LangString($lang_word, $str->nodeValue);
            } else if (empty($lang_word)){ 
                //There is no language.
                //echo "The string doesn't have the language attribute: " . $str->nodeValue ."\n";
                $aux_list[] = new LangString('und', $str->nodeValue);
            } else {
                //There is a language attribute, but not a value.
                //echo "The string have the language attribute (" .$lang_word. ") but not a value.\n";                
            }
        }
        return $aux_list;
    }//End function getLangStrings


    /**
    * This function extracts the required information from the Lifecycle label.
    */
    private function getLifecycleCategoryInfo ()
    {        

        //LIFECYCLE
        //<lifeCycle>
        //DOMNodeList $lifeCycle
        $lifeCycle = $this->ods_dom_document->getElementsByTagName('lifeCycle');
        //The number of lifeCycle elements could be 0 or 1 as maximum.
        if ($lifeCycle->length > 0){

            //Contribute labels
            //<contribute>
            //DOMNodeList $contributes
            $contributes = $lifeCycle->item(0)-> getElementsByTagName("contribute");
            //The number of Contribute labels could be 0 or more than 1.
            foreach ($contributes as $key => $contribute) {

                //Create a new instance of the LifeCycleContribute class.
                $this->ods_lifecycle_contributes[$key] = new LifeCycleContribute();

                //Entity labels
                //<entity>
                //DOMNodeList $entities
                $entities = $contribute->getElementsByTagName("entity");                
                $aux_authors = array();
                $cdata_section = false;
                //The number of Entity labels could be 0 or more than 1.
                foreach ($entities as $entity) {
                    //Get the value of the XML CDATE SECTION NODE
                    //because sometimes the VCARD is included here.
                    foreach($entity->childNodes as $child) {
                        if ($child->nodeType == XML_CDATA_SECTION_NODE) {
                            //echo "Author name: " . $child->textContent . "\n";
                            $vcard_info = $child->textContent;
                            $cdata_section = true;
                        }
                    }
                    if (!$cdata_section) {
                        //In this case the VCARD is not defined as a node in the CDATA section. It only
                        //appears as a string.
                        $vcard_info = $entity->nodeValue;
                    }
                    $author_name = "";
                    //Now we have to obtain from the vcard only the author name wich is after the string FN: 
                    //(whitespace stripped from the beginning and end of the string):
                    $pos_start = strpos($vcard_info, "FN:");
                    if ($pos_start !== false){
                        $author_name = trim(substr($vcard_info, $pos_start + 3));
                        //Now we need the string before a a new line (line feed).
                        $pos_end = strpos($author_name, "\n");
                        $author_name = substr($author_name, 0, $pos_end);
                    } else {
                        //The info does not contain the "FN:" string, therefore we take the string as it is.
                        $author_name = $vcard_info;
                    }
                    //Add the item to the matrix:
                    $aux_authors[] = $author_name;
                    //Reindex the array to the new number of authors in the array.
                    $aux_authors = array_values($aux_authors);
                }
                if ($entities->length > 0 and count($aux_authors) > 0) {
                    //Add the array to the $ods_lifecycle_contributes->author_fullnames array
                    $this->ods_lifecycle_contributes[$key]->setAuthorFullNames($aux_authors);
                }

                //Date labels
                //<date>
                //DOMNodeList $contribute_date
                $contribute_date = $contribute->getElementsByTagName('date');
                //The number of Date elements could be 0 or 1 as maximum.
                if ($contribute_date->length > 0){
                    //<dateTime>
                    //DOMNodeList $date_time
                    $date_time = $contribute_date->item(0)->getElementsByTagName('dateTime');                    
                    //The number of DateTime elements should be always 1.
                    if ($date_time->length > 0){
                        //Assign the date to the $ods_lifecycle_contributes->date                         
                        $this->ods_lifecycle_contributes[$key]->setDate($date_time->item(0)->nodeValue);
                    }
                }
            }
        }
    }//End function getLifecycleCategoryInfo

    /**
    * This function extracts the required information from the MetaMetaData label.
    * In this case, we only need the ODS metadata identifier.
    */
    private function getMetaMetaDataInfo ()
    {
        //METAMETADATA
        //<metaMetadata>
        //DOMNodeList $metametadata
        $metametadata = $this->ods_dom_document->getElementsByTagName('metaMetadata');
        //The number of general elements should be always 1.
        if ($metametadata->length > 0) {

            //Identifiers: we only need the ODS metadata identifier (catalog ="ODS").
            //<identifier>
            //DOMNodeList $identifiers
            $identifiers = $metametadata->item(0)-> getElementsByTagName("identifier");
            //The number of Identifier labels could be 0 or more than 1.
            foreach ($identifiers as $id) {
                //<catalog>
                //DOMNodeList $catalogs
                $catalogs = $id->getElementsByTagName("catalog");
                $catalog = $catalogs->item(0)->nodeValue;
                if (strcmp($catalog, "ODS") == 0){
                    //This is the ODS metadata identifier.
                    //<entry>
                    //DOMNodeList $entries
                    $entries = $id->getElementsByTagName("entry");
                    if ($entries->length > 0)
                    {                        
                        $this->ods_metadata_identifier = $entries->item(0)->nodeValue;
                    }
                } 
            }
        }
    }//End function getMetaMetaDataInfo

    /**
    * This function extracts the required information from the Technical label.
    */
    private function getTechnicalCategoryInfo ()
    {        

        //TECHNICAL
        //<technical>
        //DOMNodeList $technical
        $technical = $this->ods_dom_document->getElementsByTagName('technical');
        //The number of technical elements should be always 1.
        if ($technical->length > 0){
            //<location>
            //DOMNodeList $locations
            $locations = $technical->item(0)-> getElementsByTagName("location");
            foreach ($locations as $loc) {
                $this->ods_resource_links[] = $loc->nodeValue;
            }

            //<format>
            //DOMNodeList $formats
            $formats = $technical->item(0)-> getElementsByTagName("format");
            foreach ($formats as $frmt) {
                $this->ods_technical_formats[] = $frmt->nodeValue;
            }            
        }
    }//End function getTechnicalCategoryInfo

    /**
    * This function extracts the required information from the General label.
    */
    private function getEducationalCategoryInfo ()
    {        

        //EDUCATIONAL
        //<educational>
        //DOMNodeList $educationals
        $educationals = $this->ods_dom_document->getElementsByTagName('educational');
        //The number of educational elements could be 0 or more than 1.
        foreach ($educationals as $key => $educational) {

            //Create a new instance of the Educational class.
            $this->ods_educationals[$key] = new Educational();

            //Typical age range
            //<typicalAgeRange>
            //DOMNodeList $age_ranges
            $age_ranges = $educational->getElementsByTagName("typicalAgeRange");
            $typical_age_ranges = array();
            //The number of Typical Age Range labels could be 0 or more than 1.
            foreach ($age_ranges as $age) {
                //Each typical age range can have several language strings.
                //<string>
                //Add the new typical age range array (it has several lang strings) to the typical age ranges array:
                $typical_age_ranges[] = $this->getLangStrings($age);
            }


            if ($age_ranges->length > 0 and count($typical_age_ranges) > 0){                
                //Add the array to the $ods_educational->typical_age_ranges array representing the values of 
                //the <typicalAgeRange> label for a specific <educational> label.
                $this->ods_educationals[$key]->setTypicalAgeRanges($typical_age_ranges); 
            }

            //Context
            //<context>
            //DOMNodeList $contexts
            $contexts = $educational->getElementsByTagName('context');
            $aux_context = array();
            //The number of context elements could be 0 or more than 1.
            foreach ($contexts as $contxt) {
                //<value>
                //DOMNodeList $context_value
                $context_value = $contxt->getElementsByTagName('value'); 
                //The number of the value label should be always 1.
                if ($context_value->length > 0){
                    //Add the context to the aux array.
                    $aux_context[] = $context_value->item(0)->nodeValue;
                }
            }
            if ($contexts->length > 0 and count($aux_context) > 0)
            {
                //Add the array to the $ods_educational->contexts array representing the values of the <context> label
                //for a specific <educational> label.
                $this->ods_educationals[$key]->setContexts($aux_context);
            }

            //Learning Resource Type
            //<learningResourceType>
            //DOMNodeList $learningtypes
            $learningtypes = $educational->getElementsByTagName('learningResourceType');
            $aux_learningtype = array();
            //The number of learning resource type elements could be 0 or more than 1.
            foreach ($learningtypes as $ltype) {
                //<value>
                //DOMNodeList $ltype_value
                $ltype_value = $ltype->getElementsByTagName('value'); 
                //The number of the value label should be always 1.
                if ($ltype_value->length > 0){
                    //Add the learning resource type to the aux array.
                    $aux_learningtype[] = $ltype_value->item(0)->nodeValue;
                }
            }
            if ($learningtypes->length > 0 and count($aux_learningtype) > 0)
            {
                //Add the array to the $ods_educational->contexts array representing the values of the <context> label
                //for a specific <educational> label.
                $this->ods_educationals[$key]->setLearningResourceTypes($aux_learningtype);
            }
        }
    }//End function getEducationalCategoryInfo

    /**
    * This function extracts the required information from the Lifecycle label.
    */
    private function getRightsCategoryInfo ()
    {        

        //RIGHTS
        //<rights>
        //DOMNodeList $rights
        $rights = $this->ods_dom_document->getElementsByTagName('rights');
        //The number of Rights elements could be 0 or 1 as maximum.
        if ($rights->length > 0){

            //Copyright label
            //<copyRightAndOtherRestrictions>
            //DOMNodeList $copyrights
            $copyrights = $rights->item(0)-> getElementsByTagName("copyrightAndOtherRestrictions");
            //The number of Copyright labels could be 0 or 1 as maximum.
            if ($copyrights->length > 0){
                //<value>
                //DOMNodeList $copyright
                $copyright = $copyrights->item(0)->getElementsByTagName('value');                    
                //The number of Value elements could be 0 or 1 as maximum.
                if ($copyright->length > 0){
                    $this->ods_copyright = $copyright->item(0)->nodeValue;
                }
            }

            //Cost label
            //<cost>
            //DOMNodeList $costs
            $costs = $rights->item(0)-> getElementsByTagName("cost");
            //The number of Cost labels could be 0 or 1 as maximum.
            if ($costs->length > 0){
                //<value>
                //DOMNodeList $cost
                $cost = $costs->item(0)->getElementsByTagName('value');                    
                //The number of Value elements could be 0 or 1 as maximum.
                if ($cost->length > 0){
                    $this->ods_cost = $cost->item(0)->nodeValue;
                }
            }

        }
    }//End function getRightsCategoryInfo

    /**
    * This function extracts the required information from the Classification label.
    */
    private function getClassificationCategoryInfo ()
    {        

        //CLASSIFICATION
        //<classification>
        //DOMNodeList $classifications
        $classifications = $this->ods_dom_document->getElementsByTagName('classification');
        //The number of classification elements could be 0 or more than 1.
        foreach ($classifications as $key => $classification) {

            //Create a new instance of the Classification class.
            $this->ods_classifications[$key] = new Classification();

            //Purpose 
            //<purpose>
            //DOMNodeList $purposes
            $purposes = $classification->getElementsByTagName("purpose");
            //The number of puporses could be 0 or 1 as maximum.
            if ($purposes->length > 0)
            {
                //Value
                //<value>
                //DOMNodeList $cost
                $purpose = $purposes->item(0)->getElementsByTagName('value');                    
                //The number of Value elements could be 0 or 1 as maximum.
                if ($purpose->length > 0){
                    //Assign the value to the $ods_classifications->purpose property.
                    $this->ods_classifications[$key]->setPurpose($purpose->item(0)->nodeValue);
                }
            }

            //Taxon Path
            //<taxonPath>
            //DOMNodeList $taxonpaths
            $taxonpaths = $classification->getElementsByTagName("taxonPath");
            $aux_taxonpath_list = array();
            //The number of Taxon Path labels could be 0 or more than 1.
            foreach ($taxonpaths as $taxonpath) {
                //Taxon
                //<taxon>
                //DOMNodeList $taxons
                $taxons = $taxonpath->getElementsByTagName("taxon");
                $aux_taxon_list = array();
                //The number of Taxon labels could be 0 or more than 1.
                foreach ($taxons as $taxon) {
                    //Entry
                    //<entry>
                    //DOMNodeList $entries
                    $entries = $taxon->getElementsByTagName("entry");
                    //The number of Entry labels could be 0 or 1 as maximum.
                    if ($entries->length > 0)
                    {
                        //The entry label can have several language strings.
                        //<string>
                        //Add the new entry array (it has several lang strings) to the aux_taxon_list array:
                        $aux_taxon_list[] = $this->getLangStrings ($entries->item(0));
                    }
                }
                //Add the array of arrays to the $aux_taxonpath_list
                if ($taxonpaths->length > 0 and count($aux_taxon_list) > 0)
                {
                    $aux_taxonpath_list[] = $aux_taxon_list;
                }
            }
            if ($classifications->length > 0 and count($aux_taxonpath_list) > 0)
            {                
                $this->ods_classifications[$key]->setTaxonpaths($aux_taxonpath_list);
            }
        }

    }//End function getClassificationCategoryInfo
}//en class ODSNode

/**
* This class contains the structure of the <string> label wich type is of LangString.
*/
class LangString
{
    private $language;
    private $text;

    public function __construct ($lang, $text)
    {
        $this->language = $lang;
        $this->text = $text;
    }

    //-----------------------------
    // CLASS PROPERTIES
    //-----------------------------

    public function getLanguage(){
        return $this->language;
    }

    public function setLanguage($lang){
        $this->language = $lang;
    }

    public function getText(){
        return $this->text;
    }

    public function setText($txt){
        $this->text = $txt;
    }

}//End class LangString

/**
* This class contains the structure of the <educational> label useful for the ODS project:
* Context and Typical Age Range.
*/
class Educational 
{
    private $contexts;
    private $typical_age_ranges;
    private $learning_resource_types;

    public function __construct()
    {
        $contexts = array();
        $typical_age_ranges = array();
        $learning_resource_types = array();
    }

    //-----------------------------
    // CLASS PROPERTIES
    //-----------------------------

    public function getContexts(){
        return $this->contexts;
    }

    public function setContexts($cntxt){
        $this->contexts = $cntxt;
    }

    public function getTypicalAgeRanges(){
        return $this->typical_age_ranges;
    }

    public function setTypicalAgeRanges($ageranges){
        $this->typical_age_ranges = $ageranges;
    }

    public function getLearningResourceTypes(){
        return $this->learning_resource_types;
    }

    public function setLearningResourceTypes($resourcetypes){
        $this->learning_resource_types = $resourcetypes;
    }
}//End class Educational

/**
* This class contains the structure of the <LifeCycle> => <contribute> label useful for the ODS project:
* Entity and Date.
*/
class LifeCycleContribute 
{
    private $author_fullnames;
    private $date;

    public function __construct()
    {
        $author_fullnames = array();
        $date = "";
    }

    //-----------------------------
    // CLASS PROPERTIES
    //-----------------------------

    public function getAuthorFullNames(){
        return $this->author_fullnames;
    }

    public function setAuthorFullNames($authors){
        $this->author_fullnames = $authors;
    }

    public function getDate(){
        return $this->date;
    }

    public function setDate($dt){
        $this->date = $dt;
    }    
}//End class LifeCycleContribute

/**
* This class contains the structure of the <classification> label useful for the ODS project:
* Purpose and TaxonPath.
*/
class Classification 
{
    private $purpose;
    private $taxonpaths;

    public function __construct()
    {
        $purpose = "";
        $taxonpaths = array();
    }

    //-----------------------------
    // CLASS PROPERTIES
    //-----------------------------

    public function getPurpose(){
        return $this->purpose;
    }

    public function setPurpose($pps){
        $this->purpose = $pps;
    }

    public function getTaxonpaths(){
        return $this->taxonpaths;
    }

    public function setTaxonpaths($txnpaths){
        $this->taxonpaths = $txnpaths;
    }        
}//End class Classification

class Updater
{
    private $ods_node_info; //ODSNode.
    private $ods_repository; //Original name of the repository.
    private $ods_data_provider; //Normalized name of the repository.

    public function __construct ($lom_info)
    {
        $this->ods_node_info = $lom_info;
        try{
            //Calculate the normalized name of the repository:
            $this->ods_repository = $this->ods_node_info->getODSDataProvider();
            //echo "Repository name: ".$this->ods_repository."\n";
            //The current directory is the Drupal site (e.g. /var/www/beta) and in order to locate the file
            //we have to add the odsUpdater path.
            $this->ods_data_provider = $this->replaceWithHeuristics($this->ods_repository,"odsUpdater/repositories.ini");
            //echo "New repository name: ".$this->ods_data_provider."\n";
        }catch (HeuristicFileException $e) {
            //Display custom message
            //echo $e->errorMessage();
            throw new XMLFileException($e->errorMessage());
            
        }catch (HeuristicNameException $e) {
            throw new XMLFileException("repositories.ini: " .$e->errorMessage());            
        }
    }

    /**
    * This function calculates the normalized name of the first parameter according
    * to the heuristic file indicated in the second parameter.
    * @param $value Name to normalize.
    * @param $heuristic_file Name of the file to use.
    * @return The normalized name to be used.
    */
    private function replaceWithHeuristics($value, $heuristics_file)
    {
        // If heuristics not loaded
        if (!array_key_exists($heuristics_file, $GLOBALS['heuristics'])) {
            // Load heuristics
            if (is_file($heuristics_file) && is_readable($heuristics_file)) {
                $heuristics = parse_ini_file($heuristics_file);
                //print_r($heuristics);
                $GLOBALS['heuristics'][$heuristics_file] = $heuristics; 
                // Run heuristics
                return $this->replaceWithHeuristics($value, $heuristics_file);
            } else{
                //The file cannot be processed, then throw the exception.
                throw new HeuristicFileException($heuristics_file);
            }
        } else {
            $found = false;
            //If heuristics loaded.
            $heuristics = $GLOBALS['heuristics'][$heuristics_file];
            if (array_key_exists($value, $heuristics)) {
                foreach ($heuristics as $heuristic => $replace) {
                    if ($heuristic == $value) {
                        $value = $replace;
                        $found = true;
                        break;
                    }
                }
            }
            if ($found){
                return $value;
            } else throw new HeuristicNameException($value);
        }
    }//End function replaceWithHeuristics


    /**
     * Check if the file has an equivalente node in the Drupal database
     * (same identifier and same repository) and in this case it assigns
     * the ODS identifiers.
     * @return The node id of the Drupal portal node (-1 if it is not found).
     */
    public function checkNode()
    {
        $nid = -1;

        //First we check if the Drupal nodes have the ODS identifiers
        $general_id = $this->ods_node_info->getODSGeneralIdentifier();
        $metadata_id = $this->ods_node_info->getODSMetadataIdentifier();
        $result = db_query(
            'SELECT general.entity_id FROM {field_data_field_ods_general_identifier} general,
            {field_data_field_ods_metadata_identifier} metadata
            WHERE general.field_ods_general_identifier_value = :generalID and  
                  metadata.field_ods_metadata_identifier_value = :metadataID and
                  general.entity_id = metadata.entity_id  limit 1', 
            array(':generalID' => $general_id, ':metadataID' => $metadata_id))->fetchField();

        if ($result){                
            //It is the same node so it has to be updated.
            $nid = $result;
        } else {
            //We don't have the ODS identifiers, so we have to check the lo identifier with
            //the repository.
            $lo_identifiers = array();
            $lo_identifiers = $this->ods_node_info->getODSloIdentifiers();
            //echo "Number of identifiers: " . count($lo_identifiers) . "\n";
            foreach ($lo_identifiers as $identifier) {
                $result = db_query(
                    'SELECT lo.entity_id FROM {field_data_field_lo_identifier} lo
                    WHERE lo.field_lo_identifier_value = :identifier limit 1', 
                    array(':identifier' => $identifier))->fetchField();

                if ($result){                
                    //It is the same node so it has to be updated.
                    $nid = $result;
                    //Check if the repositories (i.e. data providers) are the same.
                    //First, we obtain the term id for the repository of our file:
                    try{
                        $taxonomy_machine_name = "repository";
                        $rep_id = $this->getTermId($this->ods_data_provider, $taxonomy_machine_name);
                        //echo "Repository ID: " . $rep_id ."\n";
                        
                        //Check if the Drupal node has the same repository ID
                        //We use the tables from the gthanos_odsdev_10 stored in the 
                        //MySQL database (use http://localhost/phpmyadmin/index.php to access it).
                        $result = db_query(
                        'SELECT dp.entity_id FROM {field_data_field_data_provider} dp
                        WHERE dp.entity_id = :nid and dp.field_data_provider_tid = :rep_id limit 1', 
                        array(':nid' => $nid, ':rep_id' => $rep_id))->fetchField();

                        if(!$result){
                            //The node has not the same repository, therefore it should not be updated.
                            $nid = -1;
                        } else{
                            //The node has the same repository and we have to update. 
                            //We finish the loop.
                            break;
                        }
                    }catch (TermNameException $e) {
                        //The repository name has not been found in the repository vocabulary.
                        throw new XMLFileException("Repository: " . $e->errorMessage());            
                    }
                }
            }
        }

        return $nid;
    }//End function checkNode

    /**
     * Obtains a term id by specifying the term and a vocabulary, if term does
     * not exist, it will be created depending of the value of the third parameter..
     * @param  string $term The term to find in the vocabulary.
     * @param  string $vocabulary The vocabulary that we have to use in order to find the term.
     * @return integer The term id of the term in the vocabulary.
     */
    private function getTermId($term, $vocabulary)
    {
        // Drupal taxonomy api
        $terms = taxonomy_get_term_by_name($term, $vocabulary);
        if (count($terms) > 0){
            //The term has been found
            foreach($terms as $key => $value)
            {
                // Return ID of the term.
                return $key;
            }
        }else throw new TermNameException($term);
    }//End function getTermId

    /**
     * Updates the drupal node with the information of our ODSNode.
     * @param  int nid The id of the Drupal node that we have to update.
     */
    public function updateNode($nid)
    {
        try {

            echo "Updating node " . $nid . "...\n";

            $node = node_load($nid);

            //Update the language of the node and the general languages.
            //echo "Updating languages... \n";
            $this->createGeneralLanguageFields($node);

            //Update title
            //echo "Updating title...\n";
            $this->createGeneralTitleField($node);

            //Update author fullname
            $this->createLifecycleContributeEntityField($node);

            //Assign the ODS identifiers.
            $node->field_ods_general_identifier['und'][0]['value'] = $this->ods_node_info->getODSGeneralIdentifier();
            $node->field_ods_metadata_identifier['und'][0]['value'] = $this->ods_node_info->getODSMetadataIdentifier();

            //Assign the source file location.
            $node->field_ods_file_location['und'][0]['value'] = $this->ods_node_info->getODSFileLocation();

            //Update description
            $this->createGeneralDescriptionField($node);

            //Update location
            $this->createTechnicalLocationField($node);

            //Update format
            $this->createTechnicalFormatField($node);

            //Update typical age range
            $this->createEducationalTypicalAgeRangeField($node);

            //Update copyright
            $this->createRightsCopyrightField($node);


            //Update cost
            $this->createRightsCostField($node);

            //Update aggregation level
            $this->createGeneralAggregationLevelField($node);

            //Update keywords
            $this->createGeneralKeywordsField($node);

            //Update classification fields: Taxon path and disclipline
            $this->createClassificationFields($node);

            //Update educational contexts
            $this->createEducationalContextsField($node);

            //Update lifecycle date
            $this->createLifecycleContributeDateField($node);

            //Prepare node for saving:
            $node = node_submit($node);
            node_save($node);   
            echo "Node updated successfully!\n\n";
        } catch (Exception $e) {
            throw new UpdateNodeException($e->getMessage());            
        }
    }//End function updateNode

    /**
    * This function assigns the language codes to the Drupal fields: language and  field_general_language.
    * The field field_general_language may have multiple values included in the taxonomy ODS AP Languages
    * (ods_ap_languages).
    * @param $node The entity to add the language fields.
    * @return The Drupal entity with the language fields filled.
    */
    private function createGeneralLanguageFields($node)
    {
        $is_first = true;

        //First we remove the values of the general language collection
        $this->clearFieldCollectionNode($node, 'field_general_language');
        if (count($this->ods_node_info->getODSLanguages()) > 0){
            foreach ($this->ods_node_info->getODSLanguages() as $lg_code) {
                try {                    
                    $language = $this->replaceWithHeuristics($lg_code, "odsUpdater/languages.ini");
                }catch (HeuristicFileException $e) {
                    throw new XMLFileException($e->errorMessage());        
                }catch (HeuristicNameException $e) {
                    throw new XMLFileException("languages.ini: " .$e->errorMessage());            
                }
                try {                    
                    $language_code = $this->replaceWithHeuristics($lg_code, "odsUpdater/language_codes.ini");
                }catch (HeuristicFileException $e) {
                    throw new XMLFileException($e->errorMessage());        
                }catch (HeuristicNameException $e) {
                    throw new XMLFileException("language_codes.ini: " .$e->errorMessage());            
                }

                if ($is_first) {
                    $node->language = $language_code;
                    $is_first = false;
                }
                try {                                    
                    $term_id = $this->getTermId($language, 'ods_ap_languages');
                    $node->field_general_language['und'][]['tid'] = $term_id;
                }catch (TermNameException $e) {
                    throw new XMLFileException("ODS AP Languages: " . $e->errorMessage());            
                }
            }
        } else {
            //The XML file didn't include Language labels, we assign the code for the
            //undefined language.
            $node->language = $GLOBALS['UND_LANG_CODE'];
        }

        return $node;
    }//End function createGeneralLanguageFields

    /**
    * This function removes the current information that has a field.
    * @param $node The entity to remove field values.
    * @param $field_name The name of the field.
    */
    private function clearFieldCollectionNode ($node, $field_name){
        //Check if the field exists.
        $total_items = 0 ;
        if (isset($node->$field_name)){
            //We obtain the language of the field
            $lang = field_language("node", $node, $field_name );
            //We check the number of items of the field.
            $total_items = count($node->{$field_name}[$lang]) ;
            for ($i=0; $i < $total_items ; $i++){
                unset($node->{$field_name}[$lang][$i]);
            }
        }
    }//End function clearFieldNode

    /**
    * This function assign the lang titles of the ods node info to the node
    * that receives as input.
    * @param Drupal entity $node The entity to add the title fields.
    * @return The Drupal entity with the title fields filled.
    */
    private function createGeneralTitleField($node)
    {

        $node->title = "";
        $title_aux = "";
        $lang_aux = "";

        //First we remove the values of the title collection
        $this->clearFieldCollectionNode($node, 'title_field');
        if (count($this->ods_node_info->getODSTitles()) >0){
            foreach ($this->ods_node_info->getODSTitles() as $key => $tit) {
                try{
                    //Normalize language code
                    $language = $this->replaceWithHeuristics($tit->getLanguage(), "odsUpdater/language_codes.ini");
                    $shortTitle = $this->ensureLength255($tit->getText());
                    //Assign the title to the node.
                    //In this case, the 'und' by default is not necessary (I don't know why, I suppose it takes
                    //"en" as the default because it shows the title in the English language).
                    $node->title_field[$language][0]['value'] = $shortTitle;
                    $node->title_field[$language][0]['safe_value'] = $shortTitle;
                    if ($key == 0) {
                        //We collect the data to use in the case we obtain a "Missing title",
                        //because we don't have a title with the same language of the node
                        //(node->language).
                        $title_aux = $shortTitle;
                        $lang_aux = $language;
                    }
                    // Check if the title language match resource language.
                    if(strcmp($language,$node->language) == 0) {
                        $node->title = $shortTitle;
                    }
                }catch (HeuristicFileException $e) {
                    throw new XMLFileException($e->errorMessage());            
                }catch (HeuristicNameException $e) {
                    throw new XMLFileException("language_codes.ini: " .$e->errorMessage());            
                }
            }
            if (empty($node->title)) {
                //The title of the node should not be empty, so instead to 
                //put the String "Missing title" (a lot of nodes could have 
                //these titles), we assign the first title that we have found
                //and we change the language of the node, in order to have the
                //same of the title language.
                //$node->title = "Missing title";
                $node->title = $title_aux;
                $node->language = $lang_aux;
                //We add the number of missing titles:
                $GLOBALS['rep_cnt_missing_titles'][$this->ods_repository]++;
            }
            return $node;
        } else throw new ODSFieldException("There is no title.\n");        
    }//End function createGeneralTitleField


    private function ensureLength255($string)
    {
        if (strlen($string) > 254) {
            $string = substr($string, 0, 254);
        }

        return $string;
    }//End function ensureLength255

    /**
    * This function assign the Author of the ods node info to the node
    * that receives as input.
    * @param Drupal entity $node The entity to add the author field.
    * @return The Drupal entity with the author field filled.
    */
    private function createLifecycleContributeEntityField($node)
    {
        if (count($this->ods_node_info->getODSLifeCycleContributes()) > 0){
            foreach ($this->ods_node_info->getODSLifeCycleContributes() as $cntr) {
                if (count($cntr->getAuthorFullNames()) > 0){
                    foreach ($cntr->getAuthorFullNames() as $author) {
                        $node->field_author_fullname['und'][0]['value'] = $this->ensureLength255($author);
                        $node->field_author_fullname['und'][0]['safe_value'] = $this->ensureLength255($author);
                        //We stop at the first author
                        return $node;
                    }
                }
            }
        } else {
            //The field has to be empty.
            $node->field_author_fullname['und'][0]['value'] = "";   
            $node->field_author_fullname['und'][0]['safe_value'] = "";   
        }
        return $node; 
    }//End function createLifecycleContributeEntityField


    /**
    * This function create the description field for the corresponding drupal node
    * @param Drupal entity $node The entity to add the description field.
    * @return The Drupal entity with the description field filled.
    */
    private function createGeneralDescriptionField($node)
    {
        $is_first = true;

        //First we remove the values of the description collection
        $this->clearFieldCollectionNode($node, 'field_eo_description');
        $this->clearFieldCollectionNode($node, 'body');
        if (count($this->ods_node_info->getODSDescriptions()) > 0){
            //We only take the first list of descriptions (i.e. the first label <description>),
            //because the description field in the Drupal node only can have 1 value.
            $descriptions_groups = $this->ods_node_info->getODSDescriptions();
            $description_list = $descriptions_groups[0];
            foreach ($description_list as $description) {
                try{
                    //Normalize language code
                    $language = $this->replaceWithHeuristics($description->getLanguage(), "odsUpdater/language_codes.ini");
                    $node->field_eo_description[$language][0]['value'] = $description->getText();
                    $node->field_eo_description[$language][0]['format'] = 'filtered_html';
                    $node->field_eo_description[$language][0]['safe_value'] = $description->getText();

                    $node->body[$language][0]['value']   = $description->getText();
                    $node->body[$language][0]['format']  = 'filtered_html';
                    $node->body[$language][0]['safe_value'] = $description->getText();

                    if($is_first) {
                        //Language 'und' by default.
                        //We take the description of the first language in the list.
                        $node->field_eo_description['und'][0]['value']   = $description->getText();
                        $node->field_eo_description['und'][0]['format']  = 'filtered_html';
                        $node->field_eo_description['und'][0]['safe_value'] = $description->getText();

                        //The body field of Drupal should have the same information.
                        $node->body['und'][0]['value']   = $description->getText();
                        $node->body['und'][0]['format']  = 'filtered_html';
                        $node->body['und'][0]['safe_value'] = $description->getText();
                        $is_first = false;
                    }
                }catch (HeuristicFileException $e) {
                    throw new XMLFileException($e->errorMessage());            
                }catch (HeuristicNameException $e) {
                    throw new XMLFileException("language_codes.ini: " .$e->errorMessage());            
                }
            }
        }else {
            //The field has to be empty.
            $node->field_eo_description['und'][0]['value']   = "";
            $node->field_eo_description['und'][0]['format']  = "";
            $node->field_eo_description['und'][0]['safe_value'] = "";
            //The body field of Drupal should have the same information.
            $node->body['und'][0]['value']   = "";
            $node->body['und'][0]['format']  = "";
            $node->body['und'][0]['safe_value'] = "";
        }
        return $node;
    }//End function createGeneralDescriptionField


    /**
    * This function create the location field for the corresponding drupal node
    * @param Drupal entity $node The entity to add the location field.
    * @return The Drupal entity with the location field filled.
    */
    private function createTechnicalLocationField($node)
    {
        if (count($this->ods_node_info->getODSResourceLinks()) > 0) {
            foreach ($this->ods_node_info->getODSResourceLinks() as $link) {

                //We find two fields with the locations: field_resource_link and field_eo_link
                $node->field_resource_link['und'][0]['url'] = $link;
                $node->field_resource_link['und'][0]['title'] = "View Resource";

                $node->field_eo_link['und'][0]['url'] = $link;
                $node->field_eo_link['und'][0]['title'] = "View Resource";

                // Return at first occurrence (only one/first technical location is supposed to be shown)
                return $node;
            }
        } else throw new ODSFieldException("There is no location.\n");
    }//End function createTechnicalLocationField


    /**
    * This function create the technical format field for the corresponding drupal node
    * @param Drupal entity $node The entity to add the technical format field.
    * @return The Drupal entity with the technical format field filled.
    */
    private function createTechnicalFormatField($node)
    {
        //First we remove the values of the technical formats collection
        $this->clearFieldCollectionNode($node, 'field_technical_format');
        if (count($this->ods_node_info->getODSTechnicalFormat()) > 0){
            foreach ($this->ods_node_info->getODSTechnicalFormat() as $format) {
                try {
                    //We obtain the id of this term in the ODS AP Technical.Format vocabulary (ods_ap_technical_format).
                    $term_id = $this->getTermId($format, 'ods_ap_technical_format');
                }catch (TermNameException $e) {
                    //If the format is not in the technical format vocabulary then we have to add this term.
                    $term_id = $this->addTermVocabulary($format, 'ods_ap_technical_format');
                }
                //We add the format to our field in the Drupal node (field_technical_format)
                $node->field_technical_format['und'][]['tid'] = $term_id;                       
            }
        }
        return $node;
    }//End function createTechnicalFormatField

    /**
    * This function create the typicalagerange field for the corresponding drupal node
    * @param Drupal entity $node The entity to add the typical age range field.
    * @return The Drupal entity with the typical age range field filled.
    */
    private function createEducationalTypicalAgeRangeField($node)
    {
        if (count($this->ods_node_info->getODSEducationals()) > 0){
            foreach ($this->ods_node_info->getODSEducationals() as $key => $educ) {
                if (count($educ->getTypicalAgeRanges()) > 0){
                    foreach ($educ->getTypicalAgeRanges() as $key2 => $age_list) {
                        foreach ($age_list as $key => $age) {
                            try {
                                //Normalize language code
                                $language = $this->replaceWithHeuristics($age->getLanguage(), "odsUpdater/language_codes.ini");
                                $node->field_educational_typicalagerang[$language][0]['value'] = $age->getText();
                                $node->field_educational_typicalagerang[$language][0]['safe_value'] = $age->getText();
                                if ($key == 0){
                                    //Language 'und' by default.                                    
                                    $node->field_educational_typicalagerang['und'][0]['value'] = $age->getText();
                                    $node->field_educational_typicalagerang['und'][0]['safe_value'] = $age->getText();
                                }
                            }catch (HeuristicFileException $e) {
                                throw new XMLFileException($e->errorMessage());            
                            }catch (HeuristicNameException $e) {
                                throw new XMLFileException("language_codes.ini: " .$e->errorMessage());            
                            }
                        }
                        //We stop at the first age range.
                        return $node;
                    } 
                }
            }
        } else {
            $node->field_educational_typicalagerang[$node->language][0]['value'] = "";
            $node->field_educational_typicalagerang[$node->language][0]['safe_value'] = "";
        } 
        return $node;
    }//End function createEducationalTypicalAgeRangeField


    /**
    * This function create the copyright field for the corresponding drupal node
    * @param Drupal entity $node The entity to add the copyright field.
    * @return The Drupal entity with the copyright field filled.
    */
    private function createRightsCopyrightField($node)
    {
        //First we remove the value of the field if it exists
        $this->clearFieldNode($node, 'field_rights_copyright');
        $copyright = $this->ods_node_info->getODSCopyright();
        if (!empty($copyright)){            
            try {
                $term_id = $this->getTermId($copyright, 'ods_ap_rights_copyright');
                $node->field_rights_copyright['und'][0]['tid'] = $term_id;
            }catch (TermNameException $e) {
                //If the copyright has not a valid term in the taxonomy we don't include
                //this field in the Drupal node. It has to be empty in the Drupal node.
                return $node;
            }
        }
        return $node; 
    }//End function createRightsCopyrightField

    /**
    * This function removes the current value that has a field.
    * @param $node The entity to remove field value.
    * @param $field_name The name of the field.
    */
    private function clearFieldNode ($node, $field_name){
        //Check if the field exists.
        if (isset($node->$field_name)){
            //We obtain the language of the field
            $lang = field_language("node", $node, $field_name );
            unset($node->{$field_name}[$lang][0]);
        }
    }//End function clearFieldNode

    /**
    * This function create the cost field for the corresponding drupal node
    * @param Drupal entity $node The entity to add the cost field.
    * @return The Drupal entity with the cost field filled.
    */
    private function createRightsCostField($node)
    {

        //First we remove the value of the field if it exists
        $this->clearFieldNode($node, 'field_rights_cost');
        $cost = $this->ods_node_info->getODSCost();
        if (!empty($cost)){
            //In the 'ods_ap_rights_cost' taxonomy we only have these values:
            //"Payment is required" and "Use is free of charge"
            if (strcmp($cost, "no") == 0)
            {
                //Replace with the term in the 'ods_ap_rights_cost' taxonomy
                $cost = "Use is free of charge";
            }else if (strcmp($cost, "yes") == 0){
                //Replace with the term in the 'ods_ap_rights_cost' taxonomy 
                $cost = "Payment is required";
            }
            try {
                $term_id = $this->getTermId($cost, 'ods_ap_rights_cost');
                $node->field_rights_cost['und'][0]['tid'] = $term_id;
            }catch (TermNameException $e) {
                //If the cost has not a valid term in the taxonomy we don't include
                //this field in the Drupal node. It has to be empty in the Drupal node.
                return $node;
            }
        }
        return $node;
    }//End function createRightsCostField

    /**
    * This function create the aggregation level field for the corresponding drupal node
    * @param Drupal entity $node The entity to add the aggregation level field.
    * @return The Drupal entity with the aggregation level field filled.
    */
    private function createGeneralAggregationLevelField($node)
    {
        //First we remove the value of the field if it exists
        $this->clearFieldNode($node, 'field_aggregation_level');
        $agg_level = $this->ods_node_info->getODSAggregationLevel();
        if (!empty($agg_level)){            
            try {
                //Normalize language code
                $agg_level_code = $this->replaceWithHeuristics($agg_level, "odsUpdater/aggregation_level.ini");
                $term_id = $this->getTermId($agg_level_code, 'ods_ap_aggregation_level');
                $node->field_aggregation_level['und'][0]['tid'] = $term_id;
            }catch (HeuristicFileException $e) {
                throw new XMLFileException($e->errorMessage());            
            }catch (HeuristicNameException $e) {
                throw new XMLFileException("aggregation_level.ini: " .$e->errorMessage());            
            }catch (TermNameException $e) {
                //If the cost has not a valid term in the taxonomy (i.e. the cost term is not included in the vocabulary)
                //we don't include this field in the Drupal node. It has to be empty in the Drupal node.
                return $node;
            }
        }
        return $node;
    }//End function createGeneralAggregationLevelField

    /**
    * This function create the keywords field (Edu tags field) for the corresponding drupal node.
    * If a keyword is not in the vocabulary, the function adds this term to the corresponding vocabulary.
    * @param Drupal entity $node The entity to add the keywords.
    * @return The Drupal entity with the Edu tags field (keywords) filled.
    */
    private function createGeneralKeywordsField($node)
    {
        //First we remove the values of the keywords collection
        $this->clearFieldCollectionNode($node, 'field_edu_tags');
        if (count($this->ods_node_info->getODSKeywords()) > 0){
            foreach ($this->ods_node_info->getODSKeywords() as $keyword_list) {
                foreach ($keyword_list as $keyword) {
                    try {
                        //We obtain the id of this term in the Edu Tags vocabulary (edu_tags).
                        $term_id = $this->getTermId($keyword->getText(), 'edu_tags');
                    }catch (TermNameException $e) {
                        //If the keyword is not in the edu_tags vocabulary then we have to add this term.
                        $term_id = $this->addTermVocabulary($keyword->getText(), 'edu_tags');
                    }
                    //We add the keyword to our field in the Drupal node (field_edu_tags)
                    $node->field_edu_tags['und'][]['tid'] = $term_id;                       

                }
            }
        }
        return $node;
    }//End function createGeneralKeywordsField

    /**
    * This function returns the term id for a given term name added to a vocabulary.
    * @param $term_name The term to add in a vocabulary.
    * @param $vocabulary The vocabulary to add the term.
    * @return The term id of the new added term.
    */
    private function addTermVocabulary($term_name, $vocabulary) {
        $vobj = taxonomy_vocabulary_machine_name_load($vocabulary);
        $term = new stdClass();
        $term->name = $this->ensureLength255($term_name);
        $term->vid = $vobj->vid;
        taxonomy_term_save($term);
        $tid = $term->tid;
        return $tid;
    }

    /**
    * This function create the taxon path and discipline classification fields for the corresponding drupal node.
    * @param Drupal entity $node The entity to add the keywords.
    * @return The Drupal entity with the Edu tags field (keywords) filled.
    */
    private function createClassificationFields($node)
    {   

        //First we remove the values of the keywords collection
        $this->clearFieldCollectionNode($node, 'field_classification_taxonpath');
        $this->clearFieldCollectionNode($node, 'field_classification_discipline');
        if (count($this->ods_node_info->getODSClassifications()) > 0){
            foreach ($this->ods_node_info->getODSClassifications() as $classif) {
                if (count($classif->getTaxonpaths()) > 0)
                {
                    foreach ($classif->getTaxonpaths() as $taxon_list) {
                        foreach ($taxon_list as $taxon_entry) {
                            foreach ($taxon_entry as $key => $langstring) {
                                try {
                                    //Normalize language code
                                    $language = $this->replaceWithHeuristics($langstring->getLanguage(), "odsUpdater/language_codes.ini");
                                    //echo "Taxon Language: " . $language . "\n";
                                    //echo "Taxon: " . $this->ensureLength255($langstring->getText()) . "\n";

                                    //Classification Taxon path
                                    //Here the problem that we have is that we can have diferente taxons in different
                                    //taxonpath labels, if they have the same language, we store in the drupal node
                                    //the taxon of the last taxon label.
                                    $node->field_classification_taxonpath[$language][]['value'] = $this->ensureLength255($langstring->getText());
                                    $node->field_classification_taxonpath[$language][]['safe_value'] = $this->ensureLength255($langstring->getText());
                                    //Language 'und' by default.                                    
                                    $node->field_classification_taxonpath['und'][]['value'] = $this->ensureLength255($langstring->getText());
                                    $node->field_classification_taxonpath['und'][]['safe_value'] = $this->ensureLength255($langstring->getText());

                                    //Classification Discipline
                                    //Check if the taxon has the separator ::
                                    if (strpos($langstring->getText(), ':')) {
                                        // Word with separators ::
                                        $entry_value = str_replace("::", ":", $langstring->getText());
                                        $exploded = explode(':', $entry_value);

                                        $last = count($exploded) - 1;
                                        //If we have more that one :: separator, the discipline is the string after
                                        //the last separator.
                                        $classification_discipline = trim($exploded[$last]);
                                        //echo "Discipline defined as: " . $classification_discipline . "\n";

                                        //We only store the term if it is in the vocabulary.
                                        $term_id = $this->getTermId($classification_discipline, 'ods_ap_classification_discipline');
                                        //echo "Found the discipline (with separator) id for the term: " . $classification_discipline . "\n";
                                        $node->field_classification_discipline[$language][]['tid'] = $term_id;
                                        $node->field_classification_discipline[$language][]['tid'] = $term_id;
                                        //Language 'und' by default.                                    
                                        $node->field_classification_discipline['und'][]['tid'] = $term_id;
                                    } else {
                                        //Single word
                                        //We only store the term if it is in the vocabulary.
                                        $term_id = $this->getTermId($this->ensureLength255($langstring->getText()), 'ods_ap_classification_discipline');
                                        //echo "Found the discipline id for the term: " . $this->ensureLength255($langstring->getText()) . "\n";
                                        $node->field_classification_discipline[$language][]['tid'] = $term_id;
                                        //Language 'und' by default.
                                        $node->field_classification_discipline['und'][]['tid'] = $term_id;
                                    }
                                }catch (HeuristicFileException $e) {
                                    throw new XMLFileException($e->errorMessage());            
                                }catch (HeuristicNameException $e) {
                                    throw new XMLFileException("language_codes.ini: " .$e->errorMessage());            
                                }catch (TermNameException $e) {
                                    //If we don't find the term in the vocabulary we don't have to do anything.
                                }
                            }
                        }
                    }
                }
            }
        }
        return $node;
    }//End function createClassificationFields

    /**
    * This function create the educational contexts field for the corresponding drupal node.
    * If a context is not in the vocabulary, the function adds this term to the corresponding vocabulary.
    * @param Drupal entity $node The entity to add the contexts.
    * @return The Drupal entity with the contexts filled.
    */
    private function createEducationalContextsField($node)
    {
        //First we remove the values of the contexts collection
        $this->clearFieldCollectionNode($node, 'field_educational_context');
        if (count($this->ods_node_info->getODSEducationals()) > 0){
            foreach ($this->ods_node_info->getODSEducationals() as $educ) {
                if (count($educ->getContexts()) > 0){
                    foreach ($educ->getContexts() as $ctxt) {
                        try {
                            //We obtain the id of this term in the Educational context vocabulary.
                            $term_id = $this->getTermId($ctxt, 'ods_ap_educational_context');
                        }catch (TermNameException $e) {
                            //If the context is not in the educational_context vocabulary then we have to add this term.
                            $term_id = $this->addTermVocabulary($ctxt, 'ods_ap_educational_context');
                        }
                        //We add the keyword to our field in the Drupal node (field_educational_context)
                        $node->field_educational_context['und'][]['tid'] = $term_id;                       
                    }
                }

            }
        }
        return $node;
    }//End function createEducationalContextsField


    /**
    * This function create the educational learning resource type field for the corresponding drupal node.
    * If a learning resource type is not in the vocabulary, the function add this term to the corresponding vocabulary.
    * @param Drupal entity $node The entity to add the contexts.
    * @return The Drupal entity with the contexts filled.
    */
    private function createEducationalLearningResourceTypeField($node)
    {
        //First we remove the values of the contexts collection
        $this->clearFieldCollectionNode($node, 'field_learning_resource_type');
        if (count($this->ods_node_info->getODSEducationals()) > 0){
            foreach ($this->ods_node_info->getODSEducationals() as $educ) {
                if (count($educ->getLearningResourceTypes()) > 0){
                    foreach ($educ->getLearningResourceTypes() as $ltypes) {
                        try {
                            //We obtain the id of this term in the Educational learning resource type vocabulary
                            //(ods_ap_educational_learningresourcetype).
                            $term_id = $this->getTermId($ltypes, 'ods_ap_educational_learningresourcetype');
                            //We add the keyword to our field in the Drupal node (field_learning_resource_type)
                            $node->field_learning_resource_type['und'][]['tid'] = $term_id;                       
                        }catch (TermNameException $e) {
                            //If the context is not in the ods_ap_educational_learningresourcetype vocabulary 
                            //then we don't have to add this term.
                        }
                    }
                }

            }
        }
        return $node;
    }//End function createEducationalLearningResourceTypeField

    /**
    * This function assign the EO update date of the ods node info to the node
    * that receives as input.
    * @param Drupal entity $node The entity to add the update date of the educational object.
    * @return The Drupal entity with the date field filled.
    */
    private function createLifecycleContributeDateField($node)
    {

        if (count($this->ods_node_info->getODSLifeCycleContributes()) > 0){
            foreach ($this->ods_node_info->getODSLifeCycleContributes() as $key =>$cntr) {
                $update_date = $cntr->getDate();
                if (!empty($update_date)) {
                    //First we check if we have a valid date:
                    //We only accept string dates with the format: yyyy-mm-dd
                    if (strlen($update_date) >= 10) {
                        $year_date = substr($update_date, 0, 4);
                        $month_date = substr($update_date, 5, 2);
                        $day_date = substr($update_date, 8, 2);
                        if (is_numeric($year_date) and is_numeric($month_date) and is_numeric($day_date)){
                            $current_year = date("Y");
                            //We check that the values represent year, month and day, respectively.
                            if ($year_date <= $current_year and $month_date >= 1 and $month_date <= 12 and 
                                $day_date >= 1 and $day_date <= 31){
                                if ($year_date < 1990){
                                    //I don't know the reason why we do that.
                                    $node->field_eo_update_date['und'][0]['value'] = date ("Y-m-d", strtotime ('1990-01-01'));
                                } else{
                                    $drupal_date = $year_date . "-" . $month_date . "-" . $day_date;
                                    $node->field_eo_update_date['und'][0]['value'] = date ("Y-m-d", strtotime ($drupal_date));
                                }
                                //Since the field_eo_update_date field only accepts one value, 
                                //we stop when we find the first valid date.
                                return $node;
                            }
                        }
                    }

                }
            }
            //If we reach this instruction is because we haven't found a valid date.
            //We assign the valid date assigned to the year 1990.
            $node->field_eo_update_date['und'][0]['value'] = date ("Y-m-d", strtotime ('1990-01-01'));
        }
        return $node;
    }//End function createMetametadataContributeDateField

    /**
     * Add a drupal node with the information of our ODSNode.
     * We know that at least the ODSNode will have one title and a location.
     * @param  int nid The id of the Drupal node that we have to update.
     */
    public function addNode(){
        try {
            echo "Creating node " . $nid . "...\n";
            $node = new stdClass();
            $node->type = 'educational_object';
            //Set some default values
            node_object_prepare($node);
            $node->uid = user_load_by_name('social updater')->uid; // Social data user
            $node->status = 1; //1 is published, 0 is unpublished
            $node->promote = 0; //0 is not promoted to home page.

            //Create the language of the node and the general languages.
            $this->createGeneralLanguageFields($node);

            //Create title
            $this->createGeneralTitleField($node);

            //Create data provider
            $this->createDataProviderField($node);

            //Create author fullname
            $this->createLifecycleContributeEntityField($node);

            //Create the lo identifier
            $this->createGeneralIdentifierField($node);

            //Create the ODS identifiers.
            $node->field_ods_general_identifier['und'][0]['value'] = $this->ods_node_info->getODSGeneralIdentifier();
            $node->field_ods_metadata_identifier['und'][0]['value'] = $this->ods_node_info->getODSMetadataIdentifier();

            //Create the source file location
            $node->field_ods_file_location['und'][0]['value'] = $this->ods_node_info->getODSFileLocation();

            //Create description
            $this->createGeneralDescriptionField($node);

            //Create location
            $this->createTechnicalLocationField($node);

            //Create format
            $this->createTechnicalFormatField($node);

            //Create typical age range
            $this->createEducationalTypicalAgeRangeField($node);

            //Create copyright
            $this->createRightsCopyrightField($node);

            //Create cost
            $this->createRightsCostField($node);

            //Create aggregation level
            $this->createGeneralAggregationLevelField($node);

            //Create keywords
            $this->createGeneralKeywordsField($node);

            //Create classification fields: Taxon path and disclipline
            $this->createClassificationFields($node);

            //Create educational contexts
            $this->createEducationalContextsField($node);

            //Create educational learning resource types
            $this->createEducationalLearningResourceTypeField($node);

            //Create lifecycle date
            $this->createLifecycleContributeDateField($node);

            //Prepare node for saving:
            if ($node = node_submit($node)){
                node_save($node);   
                echo "Node added successfully!\n\n";         
            } else throw new AddNodeException("Error when saving the node.\n");
            
        } catch (Exception $e) {
            throw new AddNodeException($e->getMessage());            
        }        
    }//End function addNode

    /**
    * This function create the data provider field for the corresponding drupal node
    * @param Drupal entity $node The entity to add the data provider field.
    * @return The Drupal entity with the data provider field filled.
    */
    private function createDataProviderField($node)
    {
        try {
            $taxonomy_machine_name = "repository";
            $rep_id = $this->getTermId($this->ods_data_provider, $taxonomy_machine_name);
            $node->field_data_provider['und'][0]['tid'] = $rep_id;
        }catch (TermNameException $e) {
            //The repository name has not been found in the vocabulary
            throw new XMLFileException("Repository: " . $e->errorMessage());
        }
        return $node;
    }//End function createDataProviderField

    /**
    * This function create the lo identifier field for the corresponding drupal node
    * @param Drupal entity $node The entity to add the lo identifier field.
    * @return The Drupal entity with the lo identifier field filled.
    */
    private function createGeneralIdentifierField($node)
    {
        $lo_list = $this->ods_node_info->getODSloIdentifiers();
        if (count($lo_list > 0)){
            //We take the first lo identifier.
            $node->field_lo_identifier['und'][0]['value'] = $lo_list[0]; 
        } else {
            //The node doesn't have any lo identifier, we discard the node (i.e. the xml file).
            throw new IdentifierException("There is no lo identifier.\n");   
        }
        return $node;
    }//End function createDataProviderField


} //End class Updater

//********************************
//CUSTOM EXCEPTION CLASSES
//********************************
class AddNodeException extends Exception {
  private $error_message;

  public function __construct($msg){
     $this->error_message = $msg;
  }
  public function errorMessage() {
    //Error message    
    $errorMsg = $this->error_message;
    return $errorMsg;
  }
}

class HeuristicFileException extends Exception {
  public function errorMessage() {
    //error message
    $errorMsg = "Error on line ".$this->getLine()." in: ".$this->getFile().
    ". The heuristic file '".$this->getMessage()."' cannot be read or it doesn't exist in the current path: ".getcwd()."\n";
    return $errorMsg;
  }
}

class HeuristicNameException extends Exception {
  public function errorMessage() {
    //error message
    $errorMsg = "Error on line ".$this->getLine()." in: ".$this->getFile().
    ". The name '".$this->getMessage()."' cannot be found in this heuristic file.\n";
    return $errorMsg;
  }
}

class IdentifierException extends Exception {
  public function errorMessage() {
    //Error message    
    $errorMsg = $this->getMessage();
    return $errorMsg;
  }
}

class TermNameException extends Exception {
  public function errorMessage() {
    //error message
    $errorMsg = "Error on line ".$this->getLine()." in: ".$this->getFile().
    ". The term name '".$this->getMessage()."' cannot be found in the vocabulary.\n";
    return $errorMsg;
  }
}

class ODSFieldException extends Exception {
  public function errorMessage() {
    //Error message    
    $errorMsg = $this->getMessage();
    return $errorMsg;
  }
}

class UpdateNodeException extends Exception {
  private $error_message;

  public function __construct($msg){
     $this->error_message = $msg;
  }
  public function errorMessage() {
    //Error message    
    $errorMsg = $this->error_message;
    return $errorMsg;
  }
}

class XMLFileException extends Exception {
  public function errorMessage() {
    //Error message    
    $errorMsg = $this->getMessage();
    return $errorMsg;
  }
}

?>
