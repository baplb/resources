<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 resources plugin for GLPI
 Copyright (C) 2009-2016 by the resources Development Team.

 https://github.com/InfotelGLPI/resources
 -------------------------------------------------------------------------

 LICENSE

 This file is part of resources.

 resources is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 resources is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with resources. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Class PluginResourcesImportResource
 */
class PluginResourcesImportResource extends CommonDBTM {

   static $rightname = 'plugin_resources_importresources';

   static $keyInOtherTables = 'plugin_resources_importresources_id';

   // Pages
   const NEW_IMPORTS = 0;
   const CONFLICTED_IMPORTS = 1;
   const VERIFY_FILE = 2;
   const VERIFY_GLPI = 3;

   // Status
   const IDENTICAL = 0;
   const DIFFERENT = 1;
   const NOT_IN_GLPI = 2;

   // Orders
   const BEFORE = 0;
   const AFTER = 1;

   static $currentStart;

   // Limitation
   const DEFAULT_LIMIT = 20;
   // We read line by 50 iteration to don't use too much ram
   const FILE_READ_MAX_LINE = 50;
   // Number of import that can be recovered from the database at ones
   const IMPORT_RECOVERY_LIMIT = 50;

   const SELECTED_FILE_DROPDOWN_NAME = "selected-file";
   const SELECTED_IMPORT_DROPDOWN_NAME = "selected-import";

   const SESSION_IMPORT_ID = "import-display-last-id";
   const SESSION_IMPORT_START = "import-display-last-start";

   const FILE_IMPORTER = false;

   static $currentVerifiedFile;

   static function getIndexUrl() {
      global $CFG_GLPI;
      return $CFG_GLPI["root_doc"] . "/plugins/resources/front/importresource.php";
   }

   static function getResourceImportFormUrl(){
      return PluginResourcesResourceImport::getFormURL(true);
   }

   static function getLocationOfVerificationFiles(){
      return GLPI_PLUGIN_DOC_DIR."/resources/import/verify";
   }

   private function resetExistingImportsArray(){
      $this->existingImports = null;
   }

   private function initExistingImportsArray(){
      if(is_null($this->existingImports)){
         $this->existingImports = $this->find();
      }
   }

   private function getStatusTitle($status) {
      switch ($status) {
         case self::IDENTICAL:
            return __('Identical to GLPI', 'resources');
         case self::DIFFERENT:
            return __('Different to GLPI', 'resources');
         case self::NOT_IN_GLPI:
            return __('Not in GLPI', 'resources');
      }
   }

   function importFileToVerify($params = []){

      $filePath = GLPI_DOC_DIR."/_tmp/".$params['_filename'][0];

      // Verify file compatibility
      if(is_null(self::verifyFileHeader($filePath))){
         return;
      }

      if(!document::moveDocument($params, $params['_filename'][0])){
         die("ERROR WHEN MOVING FILE !");
      }
   }

   /**
    * this function return the number of rows of file
    *
    * @param $filePath
    * @return int
    */
   function countRowsInFile($filePath){
      if (file_exists($filePath)) {
         return count(file($filePath));
      }
      return null;
   }

   function verifyFileHeader($filePath){
      if (file_exists($filePath)) {
         $handle = fopen($filePath, 'r');

         $importID = null;
         while (($line = fgetcsv($handle, 1000, ";")) !== FALSE) {

            $importID = $this->checkHeader($line);
            break;
         }
      }
      return $importID;
   }

   /**
    * Delete Import Resources and all child Import Resources Datas
    *
    * @param array $input
    * @param int $force
    * @param int $history
    * @return bool|void
    */
   function delete(array $input, $force = 0, $history = 1) {

      if(!isset($input[self::getIndexName()])){
         Html::displayErrorAndDie("Import resources not found");
      }

      $pluginResourcesImportResourceData = new PluginResourcesImportResourceData();

      $dataCrit = [
         self::$keyInOtherTables => $input[self::getIndexName()]
      ];

      $datas = $pluginResourcesImportResourceData->find($dataCrit);
      // Remove datas
      foreach($datas as $data){
         $pluginResourcesImportResourceData->delete([PluginResourcesImportResourceData::getIndexName() => $data['id']]);
      }

      // Remove item
      parent::delete($input, $force, $history);
   }

   /**
    * Update child Import Resources Datas
    *
    * @param $datas
    * @param $importResourceID
    */
   function updateDatas($datas, $importResourceID) {

      $pluginResourcesImportResourceData = new PluginResourcesImportResourceData();

      $crit = [
         PluginResourcesImportResourceData::$items_id => $importResourceID
      ];

      $importResourceDatas = $pluginResourcesImportResourceData->find($crit);

      foreach ($importResourceDatas as $importResourceData) {

         foreach ($datas as $data) {

            if ($data['name'] != $importResourceData['name']) {
               continue;
            }

            if ($data['value'] == $importResourceData['value']) {
               continue;
            }

            $input = [
               PluginResourcesImportResourceData::getIndexName() => $importResourceData['id'],
               "value" => addslashes($data['value'])
            ];

            $pluginResourcesImportResourceData->update($input);
            break;
         }
      }
   }

   /**
    * Insert or update imports
    *
    * @param $datas
    * @param $importID
    */
   function manageImport($datas, $importID) {

      $importResourceID = $this->isExistingImportResourceByDataFromFile($datas);

      // Override data of existing importResource
      if (!is_null($importResourceID)) {

         $this->updateDatas($datas, $importResourceID);

      } else {
         // Create new Import Resource
         $importResourceInput = [
            "date_creation" => date("Y-m-d H:i:s"),
            PluginResourcesImport::$keyInOtherTables => $importID
         ];

         $newImportId = $this->add($importResourceInput);

         $importResourceData = new PluginResourcesImportResourceData();

         // Create new Import resource data
         foreach ($datas as $item) {

            $importResourceDataInput = $importResourceData->prepareInput(
               addslashes($item['name']),
               addslashes($item['value']),
               $newImportId,
               $item['plugin_resources_importcolumns_id']
            );

            $importResourceData->add($importResourceDataInput);
         }
      }
   }

   /**
    * Search if a resource exist with the same identifiers
    *
    * @param $columnDatas
    * @return mixed|null
    */
   function isExistingImportResourceByDataFromFile($columnDatas) {

      $pluginResourcesImportResourceData = new PluginResourcesImportResourceData();

      // List of existing imports
      $this->initExistingImportsArray();

      foreach ($this->existingImports as $existingImportResource) {

         $firstLevelIdentifiers = $pluginResourcesImportResourceData->getFromParentAndIdentifierLevel($existingImportResource['id'], 1);

         $firstLevelIdentifierFounded = true;

         foreach ($firstLevelIdentifiers as $firstLevelIdentifier) {

            foreach ($columnDatas as $columnData) {

               if ($columnData['name'] != $firstLevelIdentifier['name']) {
                  continue;
               }

               if ($columnData['value'] != $firstLevelIdentifier['value']) {
                  $firstLevelIdentifierFounded = false;
                  break;
               }
            }
         }

         if ($firstLevelIdentifierFounded) {
            return $existingImportResource['id'];
         }

         $secondLevelIdentifiers = $pluginResourcesImportResourceData->getFromParentAndIdentifierLevel($existingImportResource['id'], 2);
         $secondLevelIdentifierFounded = true;

         foreach ($secondLevelIdentifiers as $secondLevelIdentifier) {

            foreach ($columnDatas as $columnData) {

               if ($columnData['name'] != $secondLevelIdentifier['name']) {
                  continue;
               }

               if ($columnData['value'] != $secondLevelIdentifier['value']) {
                  $secondLevelIdentifierFounded = false;
               }
            }
         }

         if ($secondLevelIdentifierFounded) {
            return $existingImportResource['id'];
         }
      }
      return null;
   }

   function importResourcesFromCSVFile($task) {
      // glpi files folder
      $path = GLPI_PLUGIN_DOC_DIR . "/resources/import/";
      // List of files in path
      $files = scandir($path);
      // Exclude dot and dotdot
      $files = array_diff($files, array('.', '..'));

      foreach ($files as $file) {

         $importSuccess = true;

         $filePath = $path . $file;

         // Ignore directories
         if (is_dir($filePath)) {
            continue;
         }

         $import = null;

         if (file_exists($filePath)) {
            $handle = fopen($filePath, 'r');

            // Initialize existingImports Array
            // Used to prevent multiple get imports from database
            // Speed up execution time
            $this->resetExistingImportsArray();
            $this->initExistingImportsArray();

            $importID = null;
            $header = null;

            $lineIndex = 0;
            while (($line = fgetcsv($handle, 1000, ";")) !== FALSE) {

               // First line is header description
               if ($lineIndex == 0) {

                  $importID = $this->checkHeader($line);

                  if ($importID <= 0) {
                     $importSuccess = false;
                     break;
                  }
                  $header = $line;
               // Each line contain import data
               } else {

                  $datas = $this->parseFileLine($header, $line, $importID);
                  $this->manageImport($datas, $importID);
               }
               $lineIndex++;
            }
         }
         if ($importSuccess) {
            // Move file to done folder
            $output = $path . "done/" . $file;
            rename(str_replace('\\', '/', $filePath), str_replace('\\','/', $output));

         } else {
            // Move file to fail folder
            $output = $path . "fail/" . $file;
            rename(str_replace('\\', '/', $filePath), str_replace('\\','/', $output));
         }
      }

      return true;
   }

   /**
    * Verify the header of the csv file
    *
    * Return the index of the configured import that match to this header
    *
    * @param $header
    * @return bool
    */
   function checkHeader($header) {

      $pluginResourcesImport = new PluginResourcesImport();
      $pluginResourcesImportColumn = new PluginResourcesImportColumn();

      $imports = $pluginResourcesImport->find();

      foreach ($imports as $import) {

         $crit = [
            PluginResourcesImport::$keyInOtherTables => $import['id']
         ];

         $nbOfColumns = count($pluginResourcesImportColumn->find($crit));

         if ($nbOfColumns != count($header)) {
            continue;
         }
         $sameColumnNames = true;
         $columnIndex = 0;
         foreach ($header as $item) {

            $name = addslashes($item);
            $name = $this->encodeUtf8($name);

            $crit = [
               'name' => $name,
               PluginResourcesImport::$keyInOtherTables => $import['id']
            ];

            $pluginResourcesImportColumn->getFromDBByCrit($crit);
            if ($pluginResourcesImportColumn->getID() == -1) {
               $sameColumnNames = false;
               break;
            }
            $columnIndex++;
         }
         if ($sameColumnNames) {
            return $import['id'];
         }
      }
      return false;
   }

   /**
    * Transform data in csv file to match glpi data types
    *
    * @param $header
    * @param $line
    * @param $importID
    * @return array
    */
   private function parseFileLine($header, $line, $importID) {

      $column = new PluginResourcesImportColumn();
      $datas = [];

      $headerIndex = 0;
      foreach ($header as $columnName) {

         $utf8ColumnName = addslashes($columnName);
         $utf8ColumnName = $this->encodeUtf8($utf8ColumnName);

         $crit = [
            'name' => $utf8ColumnName,
            PluginResourcesImport::$keyInOtherTables => $importID
         ];

         if (!$column->getFromDBByCrit($crit)) {
            Html::displayErrorAndDie("Import column not found");
         }

         $outType = PluginResourcesResource::getDataType($column->getField('resource_column'));

         $value = null;
         if ($this->isCastable($column->getField('type'), $outType)) {
            $value = $this->castValue($line[$headerIndex], $column->getField('type'), $outType);
         }

         $datas[] = [
            "name" => $column->getName(),
            "value" => $value,
            "plugin_resources_importcolumns_id" => intval($column->getID())
         ];

         $headerIndex++;
      }

      return $datas;
   }

   /**
    * Test if input type is castable to output type
    *
    * @param $in
    * @param $out
    * @return bool
    */
   private function isCastable($in, $out) {

      switch ($in) {
         case 0: //Integer
            switch ($out) {
               case "String":
                  return true;
               case "Contract":
                  return true;
               case "User":
                  return true;
               case "Location":
                  return true;
               case PluginResourcesDepartment::class:
                  return true;
               case "Date":
                  return false;
            }
         case 1: //Decimal
            switch ($out) {
               case "String":
                  return true;
               case "Contract":
                  return false;
               case "User":
                  return false;
               case "Location":
                  return false;
               case PluginResourcesDepartment::class:
                  return false;
               case "Date":
                  return false;
            }
         case 2: //String
            switch ($out) {
               case "String":
                  return true;
               case "Contract":
                  return true;
               case "User":
                  return true;
               case "Location":
                  return true;
               case PluginResourcesDepartment::class:
                  return true;
               case "Date":
                  return false;
            }
         case 3: //Date
            switch ($out) {
               case "String":
                  return true;
               case "Contract":
                  return false;
               case "User":
                  return false;
               case "Location":
                  return false;
               case PluginResourcesDepartment::class:
                  return false;
               case "Date":
                  return true;
            }
      }
      return false;
   }

   /**
    * Cast value from input type to output type
    *
    * @param $value
    * @param $in
    * @param $out
    * @return int|string|null
    */
   private function castValue($value, $in, $out) {
      switch ($in) {
         case 0: //Integer
            switch ($out) {
               case "String":
                  return "$value";
               case "Contract":
               case "User":
               case "Location":
               case PluginResourcesDepartment::class:
                  return $value;
            }
         case 1: //Decimal
            switch ($out) {
               case "String":
                  return $value;
            }
         case 2: //String

            $utf8String = $this->encodeUtf8($value);

            switch ($out) {
               case "String":
                  return $utf8String;
               case "Contract":
                  // CAREFUL : Contracttype is translated in database
                  return $this->getObjectIDByClassNameAndName(PluginResourcesContractType::class, $utf8String);
               case "User":
                  $userList = $this->getUserByFullname($utf8String);

                  if (count($userList)) {
                     $u = array_pop($userList);
                     return $u['id'];
                  }

                  return -1;
               case "Location":
                  return $this->getObjectIDByClassNameAndName("Location", $utf8String);
               case PluginResourcesDepartment::class:
                  return $this->getObjectIDByClassNameAndName(PluginResourcesDepartment::class, $utf8String);
            }
         case 3: //Date
            switch ($out) {
               case "String":
                  return $value;
               case "Date":
                  return $this->formatDate($value);
            }
      }
      return null;
   }

   private function formatDate($value) {
      if (self::validateDate($value)) {
         return DateTime::createFromFormat('d/m/Y', $value)->format('Y-m-d');
      } else {
         return null;
      }
   }


   function validateDate($date, $delimiter = "/"){

      $test_arr  = explode($delimiter, $date);
      if(count($test_arr) == 3){
         if (checkdate($test_arr[0], $test_arr[1], $test_arr[2]) // English date
         || checkdate($test_arr[1], $test_arr[0], $test_arr[2])) { // French date
            return true;
         }
      }
      return false;
   }

   private function encodeUtf8($value) {
      if (preg_match('!!u', $value)) {
         return $value;
      } else {
         return utf8_encode($value);
      }
   }

   /**
    * The fullname must be firstname + 1 space + lastname
    *
    * @param $fullname
    * @return array
    * @throws GlpitestSQLError
    */
   private function getUserByFullname($fullname) {
      global $DB;
      $query = "SELECT id FROM " . User::getTable() . ' WHERE CONCAT(firstname," ",realname) LIKE "' . $fullname . '"';


      $results = $DB->query($query);
      $result = [];

      while ($data = $DB->fetch_assoc($results)) {
         $result[] = $data;
      }
      return $result;
   }

   /**
    * Recover object from database by class and name
    *
    * @param $classname
    * @param $name
    * @return int
    */
   private function getObjectIDByClassNameAndName($classname, $name) {

      $item = new $classname();

      if ($item) {
         $item->getFromDBByCrit(['name' => $name]);
         return $item->getID();
      }

      // 0 is the default ID of items
      return 0;
   }

   function displayPageByType($params = []){
      switch($params['type']){
         case self::VERIFY_FILE:
         case self::VERIFY_GLPI:
            $this->verifyFilePage($params);
            break;
         case self::NEW_IMPORTS:
         case self::CONFLICTED_IMPORTS:
            $this->importFilePage($params);
            break;
         default:
            Html::displayErrorAndDie('Lost');
      }
   }

   private function dropdownImports($params){
      $defaultValue = isset($params['default']) ? $params['default'] : null;

      $pluginResourcesImport = new PluginResourcesImport();

      $names = [];
      $results = $pluginResourcesImport->find();

      foreach($results as $result){
         $names[$result['name']] = $result['name'];
      }

      Dropdown::showFromArray(self::SELECTED_IMPORT_DROPDOWN_NAME, $names, [
         'value' => $defaultValue
      ]);
   }

   /**
    * TOTO Recursive not implemented yet
    *
    * @param $name
    * @param $absoluteFolderPath
    * @param null $defaultValue
    * @param bool $recursive
    */
   private function dropdownFileInFolder($params){

      $name = $params['name'];
      $defaultValue = isset($params['default']) ? $params['default'] : null;
      $absoluteFolderPath = $params['folder'];

      if(!is_null($absoluteFolderPath) && !empty($absoluteFolderPath) && file_exists($absoluteFolderPath)){

         // List of files in path
         $files = scandir($absoluteFolderPath);
         // Exclude dot and dotdot
         $files = array_diff($files, array('.', '..'));

         foreach ($files as $key=>$file) {
            // Ignore directories
            if (is_dir( $absoluteFolderPath . $file)) {
               unset($files[$key]);
            }
         }

         if(empty($files)){
            echo __("no file to compare","resources");
         }else {

            $names = [];

            foreach($files as $file){
               if(is_null($defaultValue)){
                  $defaultValue = $file;
               }
               $names[$file] = $file;
            }

            Dropdown::showFromArray($name, $names, [
               'value' => $defaultValue
            ]);
         }
      }
      else{
         echo "<p style='color:red'>".__("The folder you expected to display content doesn't exist.", 'resources')."</p>";
      }
   }

   private function showFileImporter(){

      $formURL = self::getResourceImportFormUrl();

      echo "<form name='file-importer' method='post' action ='".$formURL."' >";
      echo "<div align='center'>";
      echo "<table>";

      echo "<tr>";
      echo "<td>";
      Html::file();
      echo "</td>";
      echo "<td>";
      echo "<input type='submit' name='import-file' class='submit' value='" . __('Import file', 'resources') . "' >";
      echo "</td>";
      echo "</tr>";

      echo "</table>";
      echo "</div>";
      Html::closeForm();
   }

   private function showFileSelector($params){

      $locationOfFiles     = $params['location'];
      $type                = $params['type'];
      $defaultFileSelected = $params['default'];

      $action = PluginResourcesImportResource::getIndexUrl();
      $action .= "?type=".$type;

      echo "<form name='file-selector' method='post' action ='".$action."' >";
      echo "<div align='center'>";
      echo "<table>";

      echo "<tr>";
      echo "<td>";

      $dropdownParams = [
         'name' => self::SELECTED_FILE_DROPDOWN_NAME,
         'folder' => $locationOfFiles,
         'default' => $defaultFileSelected
      ];

      self::dropdownFileInFolder($dropdownParams);
      echo "</td>";
      echo "<td>";
      echo "<input type='submit' name='verify' class='submit' value='" . __('Verify file', 'resources') . "' >";
      echo "</td>";
      echo "<td>";
      echo "<input type='submit' name='valid' class='submit' value='" . __('Set file ready to import', 'resources') . "' >";
      echo "</td>";
      echo "</tr>";

      echo "</table>";
      echo "</div>";
      Html::closeForm();
   }

   private function showImportSelector($params){
      global $CFG_GLPI;
      $type          = $params['type'];
      $imports       = $params['imports'];

      if(!count($imports)){
         $title = __("No imports configured","resources");
         $linkText = __("Configure a new import", "resources");
         $link = $CFG_GLPI["root_doc"] . "/plugins/resources/front/import.php";

         self::showErrorHeader($title, $linkText, $link);
      }
      else{
         $action = PluginResourcesImportResource::getIndexUrl();
         $action .= "?type=".$type;

         echo "<form name='file-selector' method='post' action ='".$action."' >";
         echo "<div align='center'>";
         echo "<table>";

         echo "<tr>";
         echo "<td>";
         self::dropdownImports($params);
         echo "</td>";
         echo "<td>";
         echo "<input type='submit' name='select' class='submit' value='" . __('Choose', 'resources') . "' >";
         echo "</td>";
         echo "</tr>";

         echo "</table>";
         echo "</div>";
         Html::closeForm();
      }
   }

   private function verifyFilePage($params = []) {

      $defaultFileSelected = "";
      if (isset($params[self::SELECTED_FILE_DROPDOWN_NAME]) && !empty($params[self::SELECTED_FILE_DROPDOWN_NAME])) {
         $defaultFileSelected = $params[self::SELECTED_FILE_DROPDOWN_NAME];
      }

      $locationOfFiles = self::getLocationOfVerificationFiles();

      echo "<div align='center'>";
      echo "<table border='0' class='tab_cadrehov'>";

      $params['location'] = $locationOfFiles;
      $params['default'] = $defaultFileSelected;

      $this->showHead($params);

      // Verify user select a file
      if (isset($params[self::SELECTED_FILE_DROPDOWN_NAME]) && !empty($params[self::SELECTED_FILE_DROPDOWN_NAME])) {

         $absoluteFilePath = self::getLocationOfVerificationFiles() . "/" . $params[self::SELECTED_FILE_DROPDOWN_NAME];

         // Verify file exist
         if(!file_exists($absoluteFilePath)){
            $title = __("File not found", "resources");
            self::showErrorHeader($title);
         }
         else{

            $importId = self::verifyFileHeader($absoluteFilePath);

            // Verify file header match a configured import
            if(!$importId){
               $title = __("The selected file doesn't match any configured import", "resources");
               self::showErrorHeader($title);
            }
            else{
               $listParams = [
                  'start' => $params['start'],
                  'limit' => $params['limit'],
                  'type' => $params['type'],
                  'file-path' => $absoluteFilePath,
                  'import-id' => $importId,
                  self::SELECTED_FILE_DROPDOWN_NAME => $params[self::SELECTED_FILE_DROPDOWN_NAME]
               ];

               switch ($params['type']) {
                  case self::VERIFY_FILE:
                     self::showVerificationFileList($listParams);
                     break;
                  case self::VERIFY_GLPI:
                     self::showVerificationGLPIFromFileList($listParams);
                     break;
               }
            }
         }
      }

      echo "</table>";
      echo "</div>";
   }

   /**
    * Display the error header
    *
    * @param $title
    * @param null $linkText
    * @param null $url
    */
   function showErrorHeader($title, $linkText = null, $url = null){
      echo "<thead>";
      echo "<tr>";

      echo "<th colspan='21'>" . $title;

      if(!is_null($linkText) && !is_null($url)){
         echo "<br>";
         echo "<a href='$url'>";
         echo $linkText;
         echo "</a>";
      }

      echo "</th>";
      echo "</thead>";
      echo "</tr>";
   }

   function displayCheckAll(){

      $script = "function checkAll(state) {";
      $script.= "var cases = document.getElementsByTagName('input');";
      $script.= "for(var i=0; i<cases.length; i++){";
      $script.= "if(cases[i].type == 'checkbox'){";
      $script.= "cases[i].checked = state;";
      $script.= "}";
      $script.= "}";
      $script.= "}";

      echo Html::scriptBlock($script);

      echo "<th class=''>";
      echo "<div class='form-group-checkbox'>";
      echo "<input title='". __("Check all") . "' type='checkbox' class='new_checkbox' name='checkall_imports' id='checkall_imports'";
      echo "onclick='checkAll(this.checked);' >";

      echo "<label class='label-checkbox' for='checkall_imports' title='" . __("Check all") . "'>";
      echo "<span class='check'></span>";
      echo "<span class='box'></span>";
      echo "</label>";
      echo "</div>";
      echo "</th>";
   }

   function showListHeader($params){

      switch($params['type']){
         case self::NEW_IMPORTS:
            echo "<tr>";
            self::displayCheckAll();
            self::displayImportColumnNames($params['import']);
            echo "</tr>";
            break;
         case self::CONFLICTED_IMPORTS:
            echo "<tr>";
            self::displayCheckAll();
            echo "<th>";
            echo __('Resource', 'resources');
            echo "</th>";
            self::displayImportColumnNames($params['import']);
            echo "</tr>";
            break;
         case self::VERIFY_FILE:
            echo "<tr>";
            foreach($params['titles'] as $key=>$title){

               echo "<th>";
               echo utf8_encode($title);
               echo "</th>";
            }

            echo "<th>";
            echo __('Status');
            echo "</th>";

            echo "</tr>";
            break;
         case self::VERIFY_GLPI:
            echo "<tr>";
            echo "<th>";
            echo "ID";
            echo "</th>";

            echo "<th>";
            echo __('Last name');
            echo "</th>";

            echo "<th>";
            echo __('First name');
            echo "</th>";

            echo "<th>";
            echo __("Identification", "resources");
            echo "</th>";

            echo "<th>";
            echo __("Informations from file", "resources");
            echo "</th>";
            echo "</tr>";
            break;
      }

   }

   /**
    * Display the header of the view
    *
    * @param $type
    * @param $import
    */
   function showHead($params) {
      echo "<thead>";

      // FIRST LINE HEADER
      echo "<tr>";

      switch($params['type']){
         case self::NEW_IMPORTS:
            echo "<th colspan='16'>" . __("Import new Resources", "resources");

            $title = sprintf(
               __('%1$s : %2$s'),
               __('Be careful, the resources will be created in the entity', 'resources'),
               Dropdown::getDropdownName('glpi_entities', $_SESSION['glpiactive_entity'])
            );

            echo "<br><span class='red'> " . $title . "</span></th>";
            break;
         case self::CONFLICTED_IMPORTS:
            $title = __("Inconsistencies between import and GLPI", "resources");
            echo "<th colspan='21'>" . $title . "</th>";
            break;
         case self::VERIFY_FILE:
            $title = __("Compare File with GLPI Resources","resources");
            echo "<th colspan='21'>" . $title . "</th>";
            break;
         case self::VERIFY_GLPI:
            $title = __("Compare GLPI Resources with File","resources");
            echo "<th colspan='21'>" . $title . "</th>";
            break;
      }

      echo "</tr>";

      echo "<tr>";

      // SECOND LINE HEADER
      switch($params['type']){
         case self::VERIFY_FILE:
         case self::VERIFY_GLPI:
            if(self::FILE_IMPORTER){
               echo "<td>";
               self::showFileImporter();
               echo "</td>";
            }
            echo "<td>";
            self::showFileSelector($params);
            echo "</td>";
            break;
         case self::CONFLICTED_IMPORTS:
         case self::NEW_IMPORTS:
            echo "<td>";
            self::showImportSelector($params);
            echo "</td>";
            break;
      }
      echo "</tr>";

      echo "</thead>";
   }

   private function displayImportColumnNames($import){
      global $CFG_GLPI;
      if(is_null($import)){
         return;
      }
      $resourceColumnNames = PluginResourcesResource::getDataNames();

      $pluginResourcesImportColumn = new PluginResourcesImportColumn();

      $importColumns = $pluginResourcesImportColumn->getColumnsByImport($import['id'], true);

      foreach ($importColumns as $importColumn) {
         echo "<th>";
         echo "<img style='vertical-align: middle;' src='" .
            $CFG_GLPI["root_doc"] . "/plugins/resources/pics/csv_file.png'" .
            " title='" . __("Data from file", "resources") . "'" .
            " width='30' height='30'>";

         $name = $resourceColumnNames[$importColumn['resource_column']];

         echo "<span style='vertical-align:middle'>" . $name . "</span>";
         echo "</th>";
      }
   }

   private function getImportResourcesLinkedWithType($params){
      $start = $params['start'];
      $type = $params['type'];
      $limit = $params['limit'];
      $import = $params['import'];

      $pluginResourcesResource = new PluginResourcesResource();

      $imports = [];

      if(!isset($_SESSION[self::SESSION_IMPORT_ID]) || is_null($_SESSION[self::SESSION_IMPORT_ID]) || $start == 0){
         $_SESSION[self::SESSION_IMPORT_ID] = 0;
      }

      if(!isset($_SESSION[self::SESSION_IMPORT_START]) || is_null($_SESSION[self::SESSION_IMPORT_START]) || $start == 0){
         $_SESSION[self::SESSION_IMPORT_START] = 0;
      }

      if($_SESSION[self::SESSION_IMPORT_START] > $start){
         $tempImportResources = self::getImportResources($import['id'], $_SESSION[self::SESSION_IMPORT_ID], self::BEFORE);
      }
      else{
         $tempImportResources = self::getImportResources($import['id'], $_SESSION[self::SESSION_IMPORT_ID], self::AFTER);
      }

      if($tempImportResources && count($tempImportResources) > 0){

         foreach($tempImportResources as $tempImportResource){

            // Find resource by importData identifiers (level 1 and level 2)
            $resourceID = $pluginResourcesResource->isExistingResourceByImportResourceID($tempImportResource['id']);
            switch ($type) {
               // Resource must not exist when NEW_IMPORTS
               case self::NEW_IMPORTS:
                  if (!$resourceID) {
                     $imports[] = $tempImportResource;
                  }
                  break;
               // Resource must exist when CONFLICTED_IMPORTS
               // And resource need to have differences with importResource
               case self::CONFLICTED_IMPORTS:

                  if($resourceID && $pluginResourcesResource->isDifferentFromImportResource($resourceID, $tempImportResource['id'])){
                     $tempImportResource['resource_id'] = $resourceID;
                     $imports[] = $tempImportResource;
                  }
                  break;
            }
            if(count($imports) == $limit){
               break;
            }
         }
      }

      if(count($imports)){
         $_SESSION[self::SESSION_IMPORT_ID] = $imports[count($imports) -1]['id'];
      }

      $_SESSION[self::SESSION_IMPORT_START] = $start;

      return $imports;
   }

   private function importFilePage($params){
      global $CFG_GLPI;

      echo "<div align='center'>";
      echo "<table border='0' class='tab_cadrehov'>";

      $pluginResourcesImport = new PluginResourcesImport();
      $imports = $pluginResourcesImport->find();

      $additionalParams = [
         'imports' => $imports
      ];

      $this->showHead(array_merge($params,$additionalParams));

      // Message when no import configured
      if (isset($params[self::SELECTED_IMPORT_DROPDOWN_NAME]) && !empty($params[self::SELECTED_IMPORT_DROPDOWN_NAME])) {
         self::showImportList($params);
      }

      echo "</table>";
      echo "</div>";
   }

   private function showImportListButtons($params){
      echo "<tr>";
      echo "<td class='center' colspan='100'>";
      switch ($params['type']) {
         case self::NEW_IMPORTS:
            echo "<input type='submit' name='add' class='submit' value='" . _sx('button', 'Add') . "' >";
            break;
         case self::CONFLICTED_IMPORTS:
            echo "<input type='submit' name='update' class='submit' value='" . _sx('button', 'Save') . "' >";
            break;
      }
      echo "&nbsp;&nbsp;<input type='submit' name='delete' class='submit' value='" . _sx('button', 'Remove an item') . "' >";
      echo "</td>";
      echo "</tr>";
   }

   private function showImportList(array $params){
      $dbu = new DbUtils();
      $pluginResourcesImport = new PluginResourcesImport();
      $pluginResourcesImport->getFromDBByCrit(['name' => $params[self::SELECTED_IMPORT_DROPDOWN_NAME]]);

      $critNbImports = ['plugin_resources_imports_id' => $pluginResourcesImport->getID()];
      $nbImports = $dbu->countElementsInTable(PluginResourcesImportResource::getTable(), $critNbImports);

      $getImportResourcesParams = $params;
      $getImportResourcesParams['import'] = $pluginResourcesImport->fields;

      $importResources = self::getImportResourcesLinkedWithType($getImportResourcesParams);

      if (!is_countable($importResources) || !count($importResources)){
         switch ($params['type']) {
            case self::NEW_IMPORTS:
               self::showErrorHeader(__('No new Imports', 'resources'));
               break;
            case self::CONFLICTED_IMPORTS:
               self::showErrorHeader(__('No Imports inconsistencies', 'resources'));
               break;
         }
      }
      else {

         // Generate pager parameters
         $parameters = "type=" . $params['type'];
         $parameters.= "&" .self::SELECTED_IMPORT_DROPDOWN_NAME;
         $parameters.= "=".$params[self::SELECTED_IMPORT_DROPDOWN_NAME];
         $formURL = self::getResourceImportFormUrl() . "?".$parameters;

         Html::printPager($params['start'], $nbImports, $_SERVER['PHP_SELF'], $parameters);

         // Deactivate last button on pager
         // TODO improve imports recover or indexing to reactive end button
         $script = 'var temp = document.querySelector(\'[title="'.__s('End').'"]\');';
         $script.= 'console.log(temp);';
         $script.= "temp.parentNode.style.display = 'none';";

         echo Html::scriptBlock($script);

         echo "<form name='form' method='post' id='import' action ='$formURL' >";
         echo "<div align='center'>";
         echo "<table border='0' class='tab_cadrehov'>";

         self::showImportListButtons($params);

         $headParams = [
            'type' => $params['type'],
            'import' => $pluginResourcesImport->fields
         ];

         self::showListHeader($headParams);

         foreach ($importResources as $importResource) {
            echo "<tr valign='center' ";
            $res = new PluginResourcesResource();
            if (isset($importResource['resource_id']) && $res->getFromDB($importResource['resource_id'])) {
               if ($res->fields['is_deleted'] == 1) {
                  echo "class='red'";
               }
            }
            echo ">";

            $resourceInput = "resource[".$importResource['id']."]";
            echo "<input type='hidden' name='$resourceInput' value='" . intval($res->getField('id')) . "'>";

            switch($params['type']){
               case self::NEW_IMPORTS:
                  $this->showOne($importResource['id'], $params['type']);
                  break;
               case self::CONFLICTED_IMPORTS:
                  $this->showOne($importResource['id'], $params['type'], $importResource['resource_id']);
                  break;
            }

            echo "</tr>";
         }

         self::showImportListButtons($params);

         echo "</table>";
         echo "</div>";
         Html::closeForm();
      }
   }

   /**
    * Display an import line
    *
    * @param $importResourceId
    * @param $type
    * @param $resourceID
    */
   function showOne($importResourceId, $type, $resourceID = null) {

      global $CFG_GLPI;

      /*
      The date need to be send to form are :
         - ResourceID
         - Data
            - resource_column
            - value
      */

      $inputs = "import[$importResourceId][%s]";

      $oldCSS = "display:block;border-bottom:solid 1px red";
      $newCSS = "display:block;border-top:solid 1px green;margin-top:1px;";

      $pluginResourcesImportResourceData = new PluginResourcesImportResourceData();

      // Get all import data
      $datas = $pluginResourcesImportResourceData->getFromParentAndIdentifierLevel($importResourceId, null, ['resource_column']);

      if (!is_null($resourceID)) {
         $pluginResourcesResource = new PluginResourcesResource();
         $pluginResourcesResource->getFromDB($resourceID);
      }

      /*
       * %s 1 : ImportID
       * %s 2 : ColumnID
       */

      echo "<td class='center' width='10'>";
      Html::showCheckbox(["name" => "select[" . $importResourceId . "]"]);
      echo "</td>";

      if($type == self::CONFLICTED_IMPORTS){

         $pluginResourcesResource = new PluginResourcesResource();
         $pluginResourcesResource->getFromDB($resourceID);

         $link = Toolbox::getItemTypeFormURL(PluginResourcesResource::getType());
         $link.= "?id=$resourceID";

         echo "<td style='text-align:center'><a href='$link'>".$resourceID."</a></td>";
      }

      $numberOfOthersValues = 0;

      foreach ($datas as $data){
         if($data['resource_column'] == 10){
            $numberOfOthersValues++;
         }
      }

      $otherIndex = 0;

      foreach ($datas as $key => $data) {

         echo "<td style='text-align:center;padding:0;'>";

            $hValue = sprintf($inputs, $data['id']);

            $textInput = "<input name='$hValue' type='hidden' value='%s'>";

            echo "<span>";
            if (!empty($data['name']) && $data['resource_column'] != 10 && $data['value'] == -1) {

               if ($type == self::NEW_IMPORTS) {
                  echo "<img style='vertical-align:middle' src='".
                     $CFG_GLPI["root_doc"] . "/plugins/resources/pics/csv_file_red.png'".
                     "title='" . __("Not Found in GLPI", "resources")."'".
                     " width='30' height='30'>";
               }
            }

            $oldValues = $resourceID && $pluginResourcesResource->hasDifferenciesWithValueByDataNameID(
                  $resourceID,
                  $data['resource_column'],
                  $data['name'],
                  $data['value']
               );

            switch ($data['resource_column']) {
               case 0:
               case 1:
                  echo sprintf($textInput, $data['value']);

                  if ($oldValues) {
                     echo "<ul>";
                     echo "<li style='$oldCSS'>";
                     $pluginResourcesResource->getFieldByDataNameID($data['resource_column']);
                     echo "</li>";
                     echo "<li style='$newCSS'>";
                  }
                  echo $data['value'];
                  if ($oldValues) {
                     echo "</li>";
                     echo "</ul>";
                  }
                  break;
               case 2:
                  if ($oldValues) {
                     echo "<ul>";
                     echo "<li style='$oldCSS'>";

                     $pluginResourcesContractType = new PluginResourcesContractType();
                     $pluginResourcesContractType->getFromDB($pluginResourcesResource->getFieldByDataNameID($data['resource_column']));
                     echo $pluginResourcesContractType->getName();

                     echo "</li>";
                     echo "<li style='$newCSS'>";
                  }
                  Dropdown::show(PluginResourcesContractType::class, [
                     'name' => $hValue,
                     'value' => $data['value'],
                     'entity' => $_SESSION['glpiactive_entity']
                  ]);
                  if ($oldValues) {
                     echo "</li>";
                     echo "</ul>";
                  }
                  break;
               case 3:
                  if ($oldValues) {
                     echo "<ul>";
                     echo "<li style='$oldCSS'>";

                     $user = new User();
                     $user->getFromDB($pluginResourcesResource->getFieldByDataNameID($data['resource_column']));
                     echo $user->getName();

                     echo "</li>";
                     echo "<li style='$newCSS'>";
                  }
                  User::dropdown([
                     'name' => $hValue,
                     'value' => $data['value'],
                     'entity' => $_SESSION['glpiactive_entity'],
                     'right' => 'all'
                  ]);
                  if ($oldValues) {
                     echo "</li>";
                     echo "</ul>";
                  }
                  break;
               case 4:
                  if ($oldValues) {
                     echo "<ul>";
                     echo "<li style='$oldCSS'>";

                     $location = new Location();
                     $location->getFromDB($pluginResourcesResource->getFieldByDataNameID($data['resource_column']));

                     echo $location->getName();
                     echo "</li>";
                     echo "<li style='$newCSS'>";
                  }
                  Dropdown::show(Location::class, [
                     'name' => $hValue,
                     'value' => $data['value'],
                     'entity' => $_SESSION['glpiactive_entity']
                  ]);
                  if ($oldValues) {
                     echo "</li>";
                     echo "</ul>";
                  }
                  break;
               case 5:
                  if ($oldValues) {
                     echo "<ul>";
                     echo "<li style='$oldCSS'>";

                     $user = new User();
                     $user->getFromDB($pluginResourcesResource->getFieldByDataNameID($data['resource_column']));
                     echo $user->getName();

                     echo "</li>";
                     echo "<li style='$newCSS'>";
                  }
                  User::dropdown([
                     'name' => $hValue,
                     'value' => $data['value'],
                     'entity' => $_SESSION['glpiactive_entity'],
                     'right' => 'all'
                  ]);
                  if ($oldValues) {
                     echo "</li>";
                     echo "</ul>";
                  }
                  break;
               case 6:
                  if ($oldValues) {
                     echo "<ul>";
                     echo "<li style='$oldCSS'>";

                     $pluginResourcesDepartment = new PluginResourcesDepartment();
                     $pluginResourcesDepartment->getFromDB($pluginResourcesResource->getFieldByDataNameID($data['resource_column']));
                     echo $pluginResourcesDepartment->getName();

                     echo "</li>";
                     echo "<li style='$newCSS'>";
                  }
                  Dropdown::show(PluginResourcesDepartment::class, [
                     'name' => $hValue,
                     'value' => $data['value'],
                     'entity' => $_SESSION['glpiactive_entity']
                  ]);
                  if ($oldValues) {
                     echo "</li>";
                     echo "</ul>";
                  }
                  break;
               case 7:
               case 8:
                  if ($oldValues) {
                     echo "<ul>";
                     echo "<li style='$oldCSS'>";

                     echo $pluginResourcesResource->getFieldByDataNameID($data['resource_column']);
                     echo "</li>";
                     echo "<li style='$newCSS'>";
                  }
                  $this->showDateFieldWithoutDiv($hValue, ['value' => $data['value']]);
                  if ($oldValues) {
                     echo "</li>";
                     echo "</ul>";
                  }
                  break;
               case 9:
                  if ($oldValues) {
                     echo "<ul>";
                     echo "<li style='$oldCSS'>";

                     $user = new User();
                     $user->getFromDB($pluginResourcesResource->getFieldByDataNameID($data['resource_column']));
                     echo $user->getName();

                     echo "</li>";
                     echo "<li style='$newCSS'>";
                  }
                  User::dropdown([
                     'name' => $hValue,
                     'value' => $data['value'],
                     'entity' => $_SESSION['glpiactive_entity'],
                     'right' => 'all'
                  ]);
                  if ($oldValues) {
                     echo "</li>";
                     echo "</ul>";
                  }
                  break;
               case 10:
                  echo sprintf($textInput, $data['value']);

                  if($otherIndex == 0){
                     echo "<table class='tab_cadrehov' style='margin:0;width:100%;'>";
                  }

                  echo "<tr>";

                  echo "<td>".$data['name']."</td>";

                  echo "<td style='color: red;'>";

                  if ($oldValues) {
                     echo $pluginResourcesResource->getResourceImportValueByName($resourceID, $data['name']);
                  }
                  echo "</td>";

                  echo "<td style='color: green;'>".$data['value']."</td>";

                  echo "</tr>";

                  if($otherIndex == $numberOfOthersValues -1){
                     echo "</table>";
                  }

                  $otherIndex++;
                  break;
            }
            echo "</span>";


         echo "</td>";
      }
   }

   /**
    * Read lines in csv file
    * Carefull the first line is the header
    *
    * @param $absoluteFilePath
    * @param $start
    * @param $limit
    */
   private function readCSVLines($absoluteFilePath, $start, $limit = INF){
      $lines = [];
      if (file_exists($absoluteFilePath)) {
         $handle = fopen($absoluteFilePath, 'r');

         $lineIndex = 0;
         while (($line = fgetcsv($handle, 1000, ";")) !== FALSE) {

            if($lineIndex >= $start){
               // Read line
               $lines[] = $line;
            }

            // End condition
            if($limit != INF && $lineIndex == $start + $limit){
               break;
            }

            $lineIndex++;
         }
         fclose($handle);
      }
      return $lines;
   }

   private function countCSVLines($absoluteFilePath){
      $nb = 0;
      if (file_exists($absoluteFilePath)) {
         $handle = fopen($absoluteFilePath, 'r');
         while (($line = fgetcsv($handle, 1000, ";")) !== FALSE) {
            $nb++;
         }
      }
      return $nb;
   }

   private function getResources($start, $limit){
      global $DB;

      $query = "SELECT *";
      $query.= " FROM ".PluginResourcesResource::getTable();
      $query.= " WHERE is_deleted = 0";
      $query.= " LIMIT ".intval($start);
      $query.= ", ".intval($limit);

      $resources = [];
      if ($result = $DB->query($query)) {
         while ($data = $DB->fetch_assoc($result)) {
            $resources[] = $data;
         }
      }

      return $resources;
   }

   private function getImportResources($importID, $importId, $order, $limit = null){
      global $DB;

      $query = "SELECT *";
      $query.= " FROM ".self::getTable();
      $query.= " WHERE plugin_resources_imports_id = ".$importID;

      $query.= " AND id ";
      $query.= ($order == self::BEFORE) ? "<" : ">" ;
      $query.= " ".$importId;

      if(!is_null($limit)){
         $query.= " LIMIT ".intval($limit);
      }

      $imports = [];
      if ($result = $DB->query($query)) {
         while ($data = $DB->fetch_assoc($result)) {
            $imports[] = $data;
         }
      }

      return $imports;
   }

   private function showVerificationGLPIFromFileList(array $params){

      $start = $params['start'];
      $type = $params['type'];
      $limit = $params['limit'];
      $importId = $params['import-id'];
      $absoluteFilePath = $params['file-path'];

      // Resource identifiers
      $pluginResourcesImportColumn = new PluginResourcesImportColumn();
      $crit = [$pluginResourcesImportColumn::$items_id => $importId];
      $columns = $pluginResourcesImportColumn->find($crit);

      // Get resources
      $resources = self::getResources($start, $limit);
      $nbOfResources = (new DBUtils)->countElementsInTable(PluginResourcesResource::getTable(), ['is_deleted' => 1]);

      // Generate pager parameters
      $parameters = "type=" . $type;
      $parameters.= "&" .self::SELECTED_FILE_DROPDOWN_NAME;
      $parameters.= "=".$params[self::SELECTED_FILE_DROPDOWN_NAME];
      $formURL = self::getIndexUrl() . "?".$parameters;

      echo "<form name='form' method='post' id='verify' action ='$formURL' >";
      echo "<div align='center'>";

      Html::printPager($start, $nbOfResources, $_SERVER['PHP_SELF'], $parameters);

      echo "<table border='0' class='tab_cadrehov'>";

      $listHeaderParams = [
         'type' => $params['type']
      ];

      self::showListHeader($listHeaderParams);

      $firstLevelResourceColumns = [];
      $secondLevelResourceColumns = [];

      $columnTitles = [];

      foreach($columns as $column){

         $columnTitles[] = $column['name'];

         // Target : table Resource or ResourceImport
         // Name : name of the column in table
         $identifier = [
            'target' => null,
            'name' => null
         ];

         switch($column['resource_column']){
            case 10:
               $identifier['target'] = PluginResourcesResourceImport::class;
               $identifier['name'] = $column['name'];
               break;
            default:
               $identifier['target'] = PluginResourcesResource::class;
               $identifier['name'] = PluginResourcesResource::getColumnName($column['resource_column']);
               break;
         }

         switch($column['is_identifier']){
            case 1:
               $firstLevelResourceColumns[] = $identifier;
               break;
            case 2:
               $secondLevelResourceColumns[] = $identifier;
               break;
         }
      }

      // The line 0 is header
      $fileReadStart = 1;

      // Find resource in file
      $lines = self::readCSVLines($absoluteFilePath, $fileReadStart);

      $pluginResourcesResourceImport = new PluginResourcesResourceImport();

      foreach($resources as $resource){

         $firstLevel = false;
         $secondLevel = false;

         $firstLevelResourceDatas = [];
         $secondLevelResourceDatas = [];

         // Values to display in differences tooltip
         $tooltipArray = [];

         // First level identifier
         foreach($firstLevelResourceColumns as $firstLevelResourceColumn){

            switch($firstLevelResourceColumn['target']){
               case PluginResourcesResourceImport::class:
                  $crit = [
                     $pluginResourcesResourceImport::$items_id => $resource['id'],
                     'name' => utf8_encode($firstLevelResourceColumn['name'])
                  ];

                  if($pluginResourcesResourceImport->getFromDBByCrit($crit)){
                     $firstLevelResourceDatas[] = $pluginResourcesResourceImport->getField('value');
                  }
                  else{
                     $firstLevelResourceDatas[] = null;
                  }
                  break;
               case PluginResourcesResource::class:

                  $firstLevelResourceDatas[] = $resource[$firstLevelResourceColumn['name']];
                  break;
            }
         }

         // Second level identifier
         foreach($secondLevelResourceColumns as $secondLevelResourceColumn){

            switch($secondLevelResourceColumn['target']){
               case PluginResourcesResourceImport::class:

                  $crit = [
                     $pluginResourcesResourceImport::$items_id => $resource['id'],
                     'name' => utf8_encode($secondLevelResourceColumn['name'])
                  ];

                  if($pluginResourcesResourceImport->getFromDBByCrit($crit)){
                     $secondLevelResourceDatas[] = $pluginResourcesResourceImport->getField('value');
                  }
                  else{
                     $secondLevelResourceDatas[] = null;
                  }

                  break;
               case PluginResourcesResource::class:

                  $secondLevelResourceDatas[] = $resource[$secondLevelResourceColumn['name']];
                  break;
            }
         }

         foreach ($lines as $line) {

            $firstLevelFound = 0;
            $firstLevelToFind = count($firstLevelResourceDatas) ;

            $secondLevelFound = 0;
            $secondLevelToFind = count($secondLevelResourceDatas) ;

            // Find identifier in line
            foreach($line as $data){

               foreach($firstLevelResourceDatas as $firstLevelResourceData){

                  if(is_null($firstLevelResourceData) || (is_string($data) && empty($data))){
                     continue;
                  }

                  if(is_string($data)){
                     $data = utf8_encode($data);
                     $data = strtolower($data);

                     $firstLevelResourceData = utf8_encode($firstLevelResourceData);
                     $firstLevelResourceData = strtolower($firstLevelResourceData);
                  }

                  if($data == $firstLevelResourceData){
                     $firstLevelFound++;
                     break;
                  }
               }
               if($firstLevelToFind == $firstLevelFound){
                  $firstLevel = true;
                  $tooltipArray = $line;
                  break 2;
               }
               else{
                  // We check second level identifiers when first was not found
                  foreach($secondLevelResourceDatas as $secondLevelResourceData){

                     if(is_null($secondLevelResourceData) || (is_string($data) && empty($data))){
                        continue;
                     }

                     if(is_string($data)){
                        $data = utf8_encode($data);
                        $data = strtolower($data);

                        $secondLevelResourceData = utf8_encode($secondLevelResourceData);
                        $secondLevelResourceData = strtolower($secondLevelResourceData);
                     }

                     if($data == $secondLevelResourceData){
                        $secondLevelFound++;
                        break;
                     }
                  }
                  if($secondLevelToFind == $secondLevelFound){
                     $secondLevel = true;
                     $tooltipArray = $line;
                     break 2;
                  }
               }
            }
         }

         echo "<tr>";
         echo "<td class='center'>";
         $link = Toolbox::getItemTypeFormURL(PluginResourcesResource::getType());
         $link.= "?id=".$resource['id'];
         echo "<a href='$link'>".$resource['id']."</a>";
         echo "</td>";
         echo "<td class='center'>";
         echo $resource['name'];
         echo "</td>";
         echo "<td class='center'>";
         echo $resource['firstname'];
         echo "</td>";
         echo "<td class='center'>";

         if(!$firstLevel && !$secondLevel){
            echo __("Not in file", "resources");
         }
         else{
            $level = "";
            if($firstLevel){
               $level = __("first level", "resources");
            }
            else if($secondLevel){
               $level = __("second level", "resources");
            }

            $identificationText = __("Find in file with %s identifier", "resources");

            echo sprintf($identificationText, $level);
         }
         echo "</td>";
         echo "<td class='center'>";
         if($firstLevel || $secondLevel){
            self::showToolTipWithArray($columnTitles, $tooltipArray);
         }
         echo "</td>";
         echo "</tr>";
      }
      echo "</table>";
      echo "</div>";
      Html::closeForm();
   }

   private function showToolTipWithArray($titles, $values, $title = null){

      if(count($titles) == count($values)){
         $content =  "<table border='0' class='tab_cadrehov'>";

         if(!is_null($title)){
            $content.= "<tr>";
            $content.= "<th>";
            $content.= $title;
            $content.= "</th>";
            $content.= "</tr>";
         }
         $content.= "<tbody>";

         for($i = 0 ; $i < count($titles) ; $i++){

            $content.= "<tr>";
            $content.= "<td class='center'>";
            $content.= $titles[$i];
            $content.= "</td>";
            $content.= "<td class='center'>";
            $content.= $values[$i];
            $content.= "</td>";
            $content.= "</tr>";
         }

         $content.= "</tbody>";
         $content.= "</table>";

         Html::showToolTip($content);
      }
      else{
         Html::showToolTip(__("Number of titles and values of tooltip doesn't match","resources"));
      }
   }

   private function showVerificationFileList(array $params){

      $start = $params['start'];
      $type = $params['type'];
      $limit = $params['limit'];
      $importId = $params['import-id'];
      $absoluteFilePath = $params['file-path'];

      // Number of lines in csv - header
      $nbLines = self::countCSVLines($absoluteFilePath) - 1;

      // The first line is header
      $startLine = ($start === 0) ? 1 : $start;
      $limitLine = ($start === 0) ? $limit + 1 : $limit;

      $lines = self::readCSVLines($absoluteFilePath, $startLine, $limitLine);

      // Generate pager parameters
      $parameters = "type=" . $type;
      $parameters.= "&" .self::SELECTED_FILE_DROPDOWN_NAME;
      $parameters.= "=".$params[self::SELECTED_FILE_DROPDOWN_NAME];

      $formURL = self::getIndexUrl() . "?".$parameters;

      // Recover the header of file FIRST LINE
      $temp = self::readCSVLines($absoluteFilePath, 0 ,1);
      $header = array_shift($temp);

      echo "<form name='form' method='post' id='verify' action ='$formURL' >";
      echo "<div align='center'>";

      Html::printPager($start, $nbLines, $_SERVER['PHP_SELF'], $parameters);

      echo "<table border='0' class='tab_cadrehov'>";

      $listHeaderParams = [
         'type' => $params['type'],
         'titles' => $header
      ];

      self::showListHeader($listHeaderParams);

      foreach($lines as $line) {

         $datas = self::parseFileLine($header, $line, $importId);

         // Find identifiers
         $firstLevelIdentifiers = [];
         $secondLevelIdentifiers = [];
         $allDatas = [];

         foreach ($datas as $data) {

            $pluginResourcesImportColumn = new PluginResourcesImportColumn();
            $pluginResourcesImportColumn->getFromDB($data['plugin_resources_importcolumns_id']);

            $element = [
               'name' => $data['name'],
               'value' => $data['value'],
               'type' => $data['plugin_resources_importcolumns_id'],
               'resource_column' => $pluginResourcesImportColumn->getField('resource_column')
            ];

            $allDatas[] = $element;

            switch ($pluginResourcesImportColumn->getField('is_identifier')) {
               case 1:
                  $firstLevelIdentifiers[] = $element;
                  break;
               case 2:
                  $secondLevelIdentifiers[] = $element;
                  break;
            }
         }

         $status = null;

         $resourceID = $this->findResource($firstLevelIdentifiers);
         if (is_null($resourceID)) {
            $resourceID = $this->findResource($secondLevelIdentifiers);
         }

         $pluginResourcesResource = new PluginResourcesResource();

         if (!$resourceID) {
            $status = self::NOT_IN_GLPI;
         } else {
            // Test Field in resources
            if ($pluginResourcesResource->isDifferentFromImportResourceDatas($resourceID, $allDatas)) {
               $status = self::DIFFERENT;
            } else {
               $status = self::IDENTICAL;
            }
         }

         echo "<tr>";

         foreach ($allDatas as $data) {
            if (!$resourceID || $pluginResourcesResource->isDifferentFromImportResourceData($resourceID, $data)) {
               echo "<td class='center' style='color:red'>";
            } else {
               echo "<td class='center'>";
            }

            echo $data['value'];
            echo "</td>";
         }

         echo "<td class='center'>";
         echo self::getStatusTitle($status);
         echo "</td>";

         echo "</tr>";

      }
      echo "</table>";
      echo "</div>";
      Html::closeForm();
   }

   private function findResource($identifiers){
      global $DB;
      $crit = [];
      $needLink = false;
      $pluginResourcesResource = new PluginResourcesResource();
      foreach ($identifiers as $identifier) {

         if (is_string($identifier['value'])) {
            $value = "'" . addslashes($identifier['value']) . "'";
         } else {
            $value = $identifier['value'];
         }

         if ($identifier['resource_column'] !== "10") {
            $crit[] = "r." . addslashes($pluginResourcesResource->getResourceColumnNameFromDataNameID($identifier['resource_column'])) . " = " . $value;
         } else {
            $needLink = true;
            $crit[] = "rd.name = '" . addslashes($identifier['name']) . "'";
            $crit[] = "rd.value = " . $value;
         }
      }

      $query = "SELECT r.id";
      $query.= " FROM ".PluginResourcesResource::getTable() . " as r";

      if($needLink){
         $query.= " INNER JOIN ".PluginResourcesResourceImport::getTable() . " as rd";
         $query.= " ON rd.".PluginResourcesResourceImport::$items_id;
         $query.= " = r.id";
      }

      for($i = 0 ; $i < count($crit) ; $i++){

         if($i == 0){
            $query .= " WHERE ";
         } else if($i > 0){
            $query .= " AND ";
         }

         $query .= $crit[$i];
      }

      $results = $DB->query($query);

      while ($data = $results->fetch_array()) {
         return $data['id'];
      }
      return null;
   }

   /**
    * Copy of html::showDateFieldWithoutDiv
    *
    * Underscore removed from name
    * Change self reference to Html
    *
    **/
   static function showDateFieldWithoutDiv($name, $options = []) {
      $p['value'] = '';
      $p['maybeempty'] = true;
      $p['canedit'] = true;
      $p['min'] = '';
      $p['max'] = '';
      $p['showyear'] = true;
      $p['display'] = true;
      $p['rand'] = mt_rand();
      $p['yearrange'] = '';

      foreach ($options as $key => $val) {
         if (isset($p[$key])) {
            $p[$key] = $val;
         }
      }
      $output = "<input id='showdate" . $p['rand'] . "' type='text' size='10' name='$name' " . "value='" . Html::convDate($p['value']) . "'>";
      $output .= Html::hidden($name, ['value' => $p['value'], 'id' => "hiddendate" . $p['rand']]);
      if ($p['maybeempty'] && $p['canedit']) {
         $output .= "<span class='fa fa-times-circle pointer' title='" . __s('Clear') . "' id='resetdate" . $p['rand'] . "'>" . "<span class='sr-only'>" . __('Clear') . "</span></span>";
      }

      $js = '$(function(){';
      if ($p['maybeempty'] && $p['canedit']) {
         $js .= "$('#resetdate" . $p['rand'] . "').click(function(){
                  $('#showdate" . $p['rand'] . "').val('');
                  $('#hiddendate" . $p['rand'] . "').val('');
                  });";
      }
      $js .= "$( '#showdate" . $p['rand'] . "' ).datepicker({
                  altField: '#hiddendate" . $p['rand'] . "',
                  altFormat: 'yy-mm-dd',
                  firstDay: 1,
                  showOtherMonths: true,
                  selectOtherMonths: true,
                  showButtonPanel: true,
                  changeMonth: true,
                  changeYear: true,
                  showOn: 'both',
                  showWeek: true,
                  buttonText: '<i class=\'far fa-calendar-alt\'></i>'";

      if (!$p['canedit']) {
         $js .= ",disabled: true";
      }

      if (!empty($p['min'])) {
         $js .= ",minDate: '" . self::convDate($p['min']) . "'";
      }

      if (!empty($p['max'])) {
         $js .= ",maxDate: '" . self::convDate($p['max']) . "'";
      }

      if (!empty($p['yearrange'])) {
         $js .= ",yearRange: '" . $p['yearrange'] . "'";
      }

      switch ($_SESSION['glpidate_format']) {
         case 1 :
            $p['showyear'] ? $format = 'dd-mm-yy' : $format = 'dd-mm';
            break;

         case 2 :
            $p['showyear'] ? $format = 'mm-dd-yy' : $format = 'mm-dd';
            break;

         default :
            $p['showyear'] ? $format = 'yy-mm-dd' : $format = 'mm-dd';
      }
      $js .= ",dateFormat: '" . $format . "'";

      $js .= "}).next('.ui-datepicker-trigger').addClass('pointer');";
      $js .= "});";
      $output .= Html::scriptBlock($js);

      if ($p['display']) {
         echo $output;
         return $p['rand'];
      }
      return $output;
   }

////// CRON FUNCTIONS ///////
   //Cron action
   /**
    * @param $name
    *
    * @return array
    */
   static function cronInfo($name) {

      switch ($name) {
         case 'ResourceImport':
            return ['description' => __('Resource files imports', 'resources')];   // Optional
            break;
      }
      return [];
   }

   /**
    * Cron action
    *
    * @param  $task for log
    * @global $CFG_GLPI
    *
    * @global $DB
    */
   static function cronResourceImport($task = NULL) {

      $CronTask = new CronTask();
      if ($CronTask->getFromDBbyName(PluginResourcesImportResource::class, "ResourceImport")) {
         if ($CronTask->fields["state"] == CronTask::STATE_DISABLE) {
            return 0;
         }
      } else {
         return 0;
      }

      $import = new self();
      return $import->importResourcesFromCSVFile($task);
   }

}