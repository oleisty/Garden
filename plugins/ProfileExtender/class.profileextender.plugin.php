<?php if (!defined('APPLICATION')) exit();
/**
 * @copyright 2013 Vanilla Forums Inc.
 * @license GNU GPL2
 */

$PluginInfo['ProfileExtender'] = array(
   'Name' => 'Profile Extender',
   'Description' => 'Add fields (like status, location, or gamer tags) to profiles and registration.',
   'Version' => '3.0',
   'RequiredApplications' => array('Vanilla' => '2.1a1'),
   'MobileFriendly' => TRUE,
   //'RegisterPermissions' => array('Plugins.ProfileExtender.Add'),
   'SettingsUrl' => '/dashboard/settings/profileextender',
   'SettingsPermission' => 'Garden.Settings.Manage',
   'Author' => "Lincoln Russell",
   'AuthorEmail' => 'lincoln@vanillaforums.com',
   'AuthorUrl' => 'http://lincolnwebs.com'
);

/**
 * Plugin to add additional fields to user profiles.
 *
 * If the field name is an existing column on user table (e.g. Title, About, Location)
 * it will store there. Otherwise, it stores in UserMeta.
 *
 * @todo Option to show in discussions
 * @todo Sort order
 * @todo Lockable for Garden.Moderation.Manage
 * @todo Date fields
 * @todo Gender, birthday adding
 * @todo Dynamic magic field filtering/linking
 * @todo Dynamic validation rule
 */
class ProfileExtenderPlugin extends Gdn_Plugin {
   /** @var array */
   public $MagicLabels = array('Twitter', 'Google', 'Facebook', 'LinkedIn', 'Website', 'Real Name');

   /**
    * Available form field types in format Gdn_Type => DisplayName.
    */
   public $FormTypes = array(
      'TextBox' => 'Text',
      'Dropdown' => 'Dropdown',
      //'CheckBox' => 'Checkbox',
   );

   /**
    * Whitelist of allowed field properties.
    */
   public $FieldProperties = array('Name', 'Label', 'FormType', 'Required', 'Locked',
      'Options', 'Length', 'Sort', 'OnRegister', 'OnProfile', 'OnDiscussion');

   /**
    * Blacklist of disallowed field names.
    * Prevents accidental or malicious overwrite of sensitive fields.
    */
   public $ReservedNames = array('Name', 'Email', 'Password', 'HashMethod', 'Admin', 'Banned', 'Points',
      'Deleted', 'Verified', 'Attributes', 'Permissions', 'Preferences');

   /**
    * Add the Dashboard menu item.
    */
   public function Base_GetAppSettingsMenuItems_Handler($Sender) {
      $Menu = &$Sender->EventArguments['SideMenu'];
      $Menu->AddLink('Users', T('Profile Fields'), 'settings/profileextender', 'Garden.Settings.Manage');
   }
   
   /**
    * Add fields to registration forms.
    */
   public function EntryController_RegisterBeforePassword_Handler($Sender) {
      $ProfileFields = C('ProfileExtender.Fields');
      foreach ($ProfileFields as $Name => $Field) {
         if (GetValue('OnRegister', $Field))
            $Sender->RegistrationFields[$Name] = $Field;
      }
      include($this->GetView('registrationfields.php'));
   }

   /**
    * Required fields on registration forms.
    */
   public function EntryController_RegisterValidation_Handler($Sender) {
      // Require new fields
      $ProfileFields = C('ProfileExtender.Fields');
      foreach ($ProfileFields as $Name => $Field) {
         // Check both so you can't break register form by requiring omitted field
         if (GetValue('Required', $Field) && GetValue('OnRegister', $Field))
            $Sender->UserModel->Validation->ApplyRule($Name, 'Required', $Field['Label']." is required.");
      }
   }
   
   /**
    * Special manipulations.
    */
   public function ParseSpecialFields($Fields = array()) {
      foreach ($Fields as $Label => $Value) {
         switch ($Label) {
            case 'Twitter':
               $Fields['Twitter'] = Anchor('@'.$Value, 'http://twitter.com/'.$Value);
               break;
            case 'Facebook':
               $Fields['Facebook'] = Anchor($Value, 'http://facebook.com/'.$Value);
               break;
            case 'LinkedIn':
               $Fields['LinkedIn'] = Anchor($Value, 'http://www.linkedin.com/in/'.$Value);
               break;
            case 'Google':
               $Fields['Google'] = Anchor('Google+', $Value, '', array('rel' => 'me'));
               break;
            case 'Website':
               $Fields['Website'] = Anchor($Value, $Value);
               break;
            case 'Real Name':
               $Fields['Real Name'] = Wrap(htmlspecialchars($Value), 'span', array('itemprop' => 'name'));
               break;
         }
      }
      
      return $Fields;
   }
      
   /**
    * Add fields to edit profile form.
    */
   public function ProfileController_EditMyAccountAfter_Handler($Sender) {
      $this->ProfileFields($Sender);
   }

   /**
    * Add custom fields to discussions.
    */
   public function Base_AuthorInfo_Handler($Sender, $Args) {
      //echo ' '.WrapIf(htmlspecialchars(GetValue('Department', $Args['Author'])), 'span', array('class' => 'MItem AuthorDepartment'));
      //echo ' '.WrapIf(htmlspecialchars(GetValue('Organization', $Args['Author'])), 'span', array('class' => 'MItem AuthorOrganization'));
   }
   
   /**
    * Display custom profile fields.
    *
    * @access private
    */
   private function ProfileFields($Sender) {
      // Retrieve user's existing profile fields
      $this->ProfileFields = C('ProfileExtender.Fields');
      $this->IsPostBack = $Sender->Form->IsPostBack();
      
      $this->UserFields = array();
      if (is_object($Sender->User))
         $this->UserFields = Gdn::UserModel()->GetMeta($Sender->User->UserID, 'Profile.%', 'Profile.');

      foreach ($this->UserFields as $Field => $Value) {
         $Sender->Form->SetValue($Field, $Value);
      }

      include($this->GetView('profilefields.php'));
   }
   
   /**
    * Settings page.
    */
   public function SettingsController_ProfileExtender_Create($Sender) {
      $Sender->Permission('Garden.Settings.Manage');
      // Detect if we need to upgrade settings
      if (!C('ProfileExtender.Fields'))
         $this->Setup();

      // Set data
      $Data = C('ProfileExtender.Fields');
      $Sender->SetData('ExtendedFields', $Data);

      $Sender->AddSideMenu('settings/profileextender');
      $Sender->SetData('Title', T('Profile Fields'));
      $Sender->Render('settings', '', 'plugins/ProfileExtender');
   }

   /**
    * Add/edit a field.
    */
   public function SettingsController_ProfileFieldAddEdit_Create($Sender, $Args) {
      $Sender->Permission('Garden.Settings.Manage');
      $Sender->SetData('Title', T('Add Profile Field'));

      if ($Sender->Form->IsPostBack()) {
         // Get whitelisted properties
         $FormPostValues = $Sender->Form->FormValues();
         foreach ($FormPostValues as $Key => $Value) {
            if (!in_array($Key, $this->FieldProperties))
               unset ($FormPostValues[$Key]);
         }

         // Make Options an array
         if ($Options = GetValue('Options', $FormPostValues)) {
            SetValue('Options', $FormPostValues, explode("\n", $Options));
         }

         // Merge updated data into config
         $Fields = C('ProfileExtender.Fields');
         if (!$Name = GetValue('Name', $FormPostValues)) {
            // Make unique name from label for new fields
            $Name = $TestSlug = preg_replace('`[^0-9a-zA-Z]`', '', GetValue('Label', $FormPostValues));
            $i = 1;
            while (array_key_exists($Name, $Fields) || in_array($Name, $this->ReservedNames)) {
               $Name = $TestSlug.$i++;
            }
         }
         $Data = C('ProfileExtender.Fields.'.$Name, array());
         $Data = array_merge($Data, (array)$FormPostValues);
         SaveToConfig('ProfileExtender.Fields.'.$Name, $Data);
         $Sender->RedirectUrl = Url('/settings/profileextender');
      }
      elseif (isset($Args[0])) {
         // Editing
         $Data = C('ProfileExtender.Fields.'.$Args[0]);
         if (isset($Data['Options']) && is_array($Data['Options']))
            $Data['Options'] = implode("\n", $Data['Options']);
         $Sender->Form->SetData($Data);
         $Sender->Form->AddHidden('Name', $Args[0]);
         $Sender->SetData('Title', T('Edit Profile Field'));
      }

      $Sender->SetData('FormTypes', $this->FormTypes);
      $Sender->Render('addedit', '', 'plugins/ProfileExtender');
   }

   /**
    * Delete a field.
    */
   public function SettingsController_ProfileFieldDelete_Create($Sender, $Args) {
      $Sender->Permission('Garden.Settings.Manage');
      $Sender->SetData('Title', 'Delete Field');
      if (isset($Args[0])) {
         if ($Sender->Form->IsPostBack()) {
            RemoveFromConfig('ProfileExtender.Fields.'.$Args[0]);
            $Sender->RedirectUrl = Url('/settings/profileextender');
         }
         else
            $Sender->SetData('Field', C('ProfileExtender.Fields.'.$Args[0]));
      }
      $Sender->Render('delete', '', 'plugins/ProfileExtender');
   }
   
   /**
    * Display custom fields on Edit User form.
    */
   public function UserController_AfterFormInputs_Handler($Sender) {
      echo '<ul>';
      $this->ProfileFields($Sender);
      echo '</ul>';
   }

   /**
    * Display custom fields on Profile.
    */
   public function UserInfoModule_OnBasicInfo_Handler($Sender) {
      try {
         // Get the custom fields
         $ProfileFields = Gdn::UserModel()->GetMeta($Sender->User->UserID, 'Profile.%', 'Profile.');

         // Import from CustomProfileFields if available
         if (!count($ProfileFields) && is_object($Sender->User) && C('Plugins.CustomProfileFields.SuggestedFields', FALSE)) {
            $ProfileFields = Gdn::UserModel()->GetAttribute($Sender->User->UserID, 'CustomProfileFields', FALSE);
			   if ($ProfileFields) {
			      // Migrate to UserMeta & delete original
			      Gdn::UserModel()->SetMeta($Sender->User->UserID, $ProfileFields, 'Profile.');
			      Gdn::UserModel()->SaveAttribute($Sender->User->UserID, 'CustomProfileFields', FALSE);
			   }
         }
         
         // Send them off for magic formatting
         $ProfileFields = $this->ParseSpecialFields($ProfileFields);
         
         // Display all non-hidden fields
         $AllFields = C('ProfileExtender.Fields');
         foreach ($ProfileFields as $Name => $Value) {
            if (!$Value)
               continue;
            if (!GetValue('OnProfile', $AllFields[$Name]))
               continue;
            if (!in_array($Name, $this->MagicLabels))
               $Value = Gdn_Format::Links(htmlspecialchars($Value));
            echo ' <dt class="ProfileExtend Profile'.Gdn_Format::AlphaNumeric($Name).'">'.Gdn_Format::Text($AllFields[$Name]['Label']).'</dt> ';
            echo ' <dd class="ProfileExtend Profile'.Gdn_Format::AlphaNumeric($Name).'">'.$Value.'</dd> ';
         }
      } catch (Exception $ex) {
         // No errors
      }
   }
   
   /**
    * Save custom profile fields when saving the user.
    */
   public function UserModel_AfterSave_Handler($Sender) {
      // Confirm we have submitted form values
      $FormPostValues = GetValue('FormPostValues', $Sender->EventArguments);

      if (is_array($FormPostValues)) {
         $UserID = GetValue('UserID', $Sender->EventArguments);
         $AllowedFields = C('ProfileExtender.Fields');
         $Columns = Gdn::SQL()->FetchColumns('User');

         foreach ($FormPostValues as $Name => $Field) {
            // Whitelist
            if (!array_key_exists($Name, $AllowedFields))
               unset($FormPostValues[$Name]);

            // Don't allow duplicates on User table
            if (in_array($Name, $Columns))
               unset($FormPostValues[$Name]);
         }

         // Update UserMeta if any made it thru
         if (count($FormPostValues))
            Gdn::UserModel()->SetMeta($UserID, $FormPostValues, 'Profile.');
      }
   }
   
   /**
    * Import from CustomProfileFields or upgrade from ProfileExtender 2.0.
    */
   public function Setup() {
      if ($Fields = C('Plugins.ProfileExtender.ProfileFields', C('Plugins.CustomProfileFields.SuggestedFields'))) {
         // Get defaults
         $Hidden = C('Plugins.ProfileExtender.HideFields', C('Plugins.CustomProfileFields.HideFields'));
         $OnRegister = C('Plugins.ProfileExtender.RegistrationFields');
         $Length = C('Plugins.ProfileExtender.TextMaxLength', C('Plugins.CustomProfileFields.ValueLength'));

         // Convert to arrays
         $Fields = array_filter((array)explode(',', $Fields));
         $Hidden = array_filter((array)explode(',', $Hidden));
         $OnRegister = array_filter((array)explode(',', $OnRegister));

         // Assign new data structure
         $NewData = array();
         foreach ($Fields as $Field) {
            // Make unique slug
            $Name = $TestSlug = preg_replace('`[^0-9a-zA-Z]`', '', $Field);
            $i = 1;
            while (array_key_exists($Name, $NewData) || in_array($Name, $this->ReservedNames)) {
               $Name = $TestSlug.$i++;
            }

            // Convert
            $NewData[$Name] = array(
               'Label' => $Field,
               'Length' => $Length,
               'FormType' => 'TextBox',
               'OnProfile' => (in_array($Field, $Hidden)) ? 0 : 1,
               'OnRegister' => (in_array($Field, $OnRegister)) ? 1 : 0,
               'OnDiscussion' => 0,
               'Required' => 0,
               'Locked' => 0,
               'Sort' => 0
            );
         }
         SaveToConfig('ProfileExtender.Fields', $NewData);
      }
   }
}

// 2.0 used these config settings; the first 3 were a comma-separated list of field names.
//'Plugins.ProfileExtender.ProfileFields' => array('Control' => 'TextBox', 'Options' => array('MultiLine' => TRUE)),
//'Plugins.ProfileExtender.RegistrationFields' => array('Control' => 'TextBox', 'Options' => array('MultiLine' => TRUE)),
//'Plugins.ProfileExtender.HideFields' => array('Control' => 'TextBox', 'Options' => array('MultiLine' => TRUE)),
//'Plugins.ProfileExtender.TextMaxLength' => array('Control' => 'TextBox'),
