<?php
/**
 * Basic data-object representing all pages within the site tree.
 * This data-object takes care of the heirachy.  All page types that live within the heirachy
 * should inherit from this.
 *
 * In addition, it contains a number of static methods for querying the site tree.
 * @package cms
 */
class SiteTree extends DataObject implements PermissionProvider,i18nEntityProvider {

	/**
	 * Indicates what kind of children this page type can have.
	 * This can be an array of allowed child classes, or the string "none" -
	 * indicating that this page type can't have children.
	 * If a classname is prefixed by "*", such as "*Page", then only that
	 * class is allowed - no subclasses. Otherwise, the class and all its
	 * subclasses are allowed.
	 *
	 * @var array
	 */
	static $allowed_children = array("SiteTree");

	/**
	 * The default child class for this page.
	 *
	 * @var string
	 */
	static $default_child = "Page";

	/**
	 * The default parent class for this page.
	 *
	 * @var string
	 */
	static $default_parent = null;

	/**
	 * Controls whether a page can be in the root of the site tree.
	 *
	 * @var bool
	 */
	static $can_be_root = true;

	/**
	 * List of permission codes a user can have to allow a user to create a
	 * page of this type.
	 *
	 * @var array
	 */
	static $need_permission = null;

	/**
	 * If you extend a class, and don't want to be able to select the old class
	 * in the cms, set this to the old class name. Eg, if you extended Product
	 * to make ImprovedProduct, then you would set $hide_ancestor to Product.
	 *
	 * @var string
	 */
	static $hide_ancestor = null;

	static $db = array(
		"URLSegment" => "Varchar(255)",
		"Title" => "Varchar(255)",
		"MenuTitle" => "Varchar(100)",
		"Content" => "HTMLText",
		"MetaTitle" => "Varchar(255)",
		"MetaDescription" => "Text",
		"MetaKeywords" => "Varchar(255)",
		"ExtraMeta" => "HTMLText",
		"ShowInMenus" => "Boolean",
		"ShowInSearch" => "Boolean",
		"HomepageForDomain" => "Varchar(100)",
		"ProvideComments" => "Boolean",
		"Sort" => "Int",
		"HasBrokenFile" => "Boolean",
		"HasBrokenLink" => "Boolean",
		"Status" => "Varchar",
		"ReportClass" => "Varchar",
		"CanViewType" => "Enum('Anyone, LoggedInUsers, OnlyTheseUsers, Inherit', 'Inherit')",
		"CanEditType" => "Enum('LoggedInUsers, OnlyTheseUsers, Inherit', 'Inherit')",

		// Simple task tracking
		"ToDo" => "Text",
	);

	static $indexes = array(
		"SearchFields" => Array('type'=>'fulltext', 'name'=>'SearchFields', 'value'=>'Title, MenuTitle, Content, MetaTitle, MetaDescription, MetaKeywords'),
		//"TitleSearchFields" => Array('type'=>'fulltext', 'value'=>'Title'),
		//"ContentSearchFields" => Array('type'=>'fulltext', 'value'=>'Content'),
		"URLSegment" => true,
	);

	static $has_many = array(
		"Comments" => "PageComment"
	);

	static $many_many = array(
		"LinkTracking" => "SiteTree",
		"ImageTracking" => "File",
		"ViewerGroups" => "Group",
		"EditorGroups" => "Group",
		"UsersCurrentlyEditing" => "Member",
	);

	static $belongs_many_many = array(
		"BackLinkTracking" => "SiteTree"
	);

	static $many_many_extraFields = array(
		"UsersCurrentlyEditing" => array("LastPing" => "SSDatetime"),
		"LinkTracking" => array("FieldName" => "Varchar"),
		"ImageTracking" => array("FieldName" => "Varchar")
	);

	static $casting = array(
		"Breadcrumbs" => "HTMLText",
		"LastEdited" => "SSDatetime",
		"Created" => "SSDatetime",
	);

	static $defaults = array(
		"ShowInMenus" => 1,
		"ShowInSearch" => 1,
		"Status" => "New page",
		"CanViewType" => "Inherit",
		"CanEditType" => "Inherit"
	);

	static $has_one = array(
		"Parent" => "SiteTree"
	);

	static $versioning = array(
		"Stage",  "Live"
	);

	static $default_sort = "Sort";

	/**
	 * The text shown in the create page dropdown. If
	 * this is not set, default to "Create a ClassName".
	 * 
	 * @deprecated 2.3 Use "<myclassname>.TITLE" in the i18n language tables instead
	 * @var string
	 */
	static $add_action = null;

	/**
	 * If this is false, the class cannot be created in the CMS.
	 * @var boolean
	*/
	static $can_create = true;

	/**
	 * If this is true, users can create only one instance of this class in the CMS. 
	 */
	static $single_instance_only = false;
	
	/**
	 * This is used as a CSS class to indicate a sitetree node is a single_instance_only page type
	 */
	static $single_instance_only_css_class = 'singleinstanceonly';
	
	/**
	 * Icon to use in the CMS
	 *
	 * This should be the base filename.  The suffixes -file.gif,
	 * -openfolder.gif and -closedfolder.gif will be appended to the base name
	 * that you provide there.
	 * If you prefer, you can pass an array:
	 * array("jsparty\tree\images\page", $option).
	 * $option can be either "file" or "folder" to force the icon to always
	 * be a file or folder, regardless of whether the page has children or not
	 *
	 * @var string|array
	 */
	static $icon = array("jsparty/tree/images/page", "file");


	static $extensions = array(
		"Hierarchy",
		"Versioned('Stage', 'Live')",
	);
	
	/**
	 * Delimit breadcrumb-links generated by BreadCrumbs()
	 *
	 * @var string
	 */
	public static $breadcrumbs_delimiter = " &raquo; ";
	
	static $searchable_fields = array(
		'Title',
		'Content',
	);
	
	/**
	 * @see SiteTree::nested_urls()
	 */
	private static $nested_urls = false;
	
	/**
	 * This controls whether of not extendCMSFields() is called by getCMSFields.
	 */
	private static $runCMSFieldsExtensions = true;
	
	/**
	 * Cache for canView/Edit/Publish/Delete permissions
	 */
	private static $cache_permissions = array();
	
	/**
	 * Returns TRUE if nested URLs (e.g. page/sub-page/) are currently enabled on this site.
	 *
	 * @return bool
	 */
	public static function nested_urls() {
		return self::$nested_urls;
	}
	
	public static function enable_nested_urls() {
		self::$nested_urls = true;
	}
	
	public static function disable_nested_urls() {
		self::$nested_urls = false;
	}
	
	/**
	 * Fetches the {@link SiteTree} object that maps to a link.
	 *
	 * If you have enabled {@link SiteTree::nested_urls()} on this site, then you can use a nested link such as
	 * "about-us/staff/", and this function will traverse down the URL chain and grab the appropriate link.
	 *
	 * Note that if no model can be found, this method will fall over to a decorated alternateGetByLink method provided
	 * by a decorator attached to {@link SiteTree}
	 *
	 * @param string $link
	 * @param bool $cache
	 * @return SiteTree
	 */
	public static function get_by_link($link, $cache = true) {
		if(trim($link, '/')) {
			$link = trim(Director::makeRelative($link), '/');
		} else {
			$link = RootURLController::get_homepage_link();
		}
		
		$parts = Convert::raw2sql(preg_split('|/+|', $link));
		
		// Grab the initial root level page to traverse down from.
		$URLSegment = array_shift($parts);
		$sitetree   = DataObject::get_one (
			'SiteTree', "\"URLSegment\" = '$URLSegment'" . (self::nested_urls() ? ' AND "ParentID" = 0' : ''), $cache
		);
		
		/// Fall back on a unique URLSegment for b/c.
		if(!$sitetree && self::nested_urls() && $pages = DataObject::get('SiteTree', "\"URLSegment\" = '$URLSegment'")) {
			return ($pages->Count() == 1) ? $pages->First() : null;
		}
		
		// Attempt to grab an alternative page from decorators.
		if(!$sitetree) {
			if($alternatives = singleton('SiteTree')->extend('alternateGetByLink', $link, $filter, $cache, $order)) {
				foreach($alternatives as $alternative) if($alternative) return $alternative;
			}
			
			return false;
		}
		
		// Check if we have any more URL parts to parse.
		if(!self::nested_urls() || !count($parts)) return $sitetree;
		
		// Traverse down the remaining URL segments and grab the relevant SiteTree objects.
		foreach($parts as $segment) {
			$next = DataObject::get_one (
				'SiteTree', "\"URLSegment\" = '$segment' AND \"ParentID\" = $sitetree->ID", $cache
			);
			
			if(!$next) {
				if($alternatives = singleton('SiteTree')->extend('alternateGetByLink', $link, $filter, $cache, $order)) {
					foreach($alternatives as $alternative) if($alternative) return $alternative;
				}
				
				return false;
			}
			
			$sitetree->destroy();
			$sitetree = $next;
		}
		
		return $sitetree;
	}
	
	/**
	 * Return a subclass map of SiteTree
	 * that shouldn't be hidden through
	 * {@link SiteTree::$hide_ancestor}
	 *
	 * @return array
	 */
	public static function page_type_classes() {
		$classes = ClassInfo::getValidSubClasses();
		array_shift($classes);
		$kill_ancestors = array();

		// figure out if there are any classes we don't want to appear
		foreach($classes as $class) {
			$instance = singleton($class);

			// do any of the progeny want to hide an ancestor?
			if($ancestor_to_hide = $instance->stat('hide_ancestor')) {
				// note for killing later
				$kill_ancestors[] = $ancestor_to_hide;
			}
		}

		// If any of the descendents don't want any of the elders to show up, cruelly render the elders surplus to requirements.
		if($kill_ancestors) {
			$kill_ancestors = array_unique($kill_ancestors);
			foreach($kill_ancestors as $mark) {
				// unset from $classes
				$idx = array_search($mark, $classes);
				unset($classes[$idx]);
			}
		}
		
		return $classes;
	}
	
	/**
	 * Replace a "[sitetree_link id=n]" shortcode with a link to the page with the corresponding ID.
	 *
	 * @return string
	 */
	public static function link_shortcode_handler($arguments, $content = null, $parser = null) {
		if(!isset($arguments['id']) || !is_numeric($arguments['id'])) return;
		
		if (
			   !($page = DataObject::get_by_id('SiteTree', $arguments['id']))         // Get the current page by ID.
			&& !($page = Versioned::get_latest_version('SiteTree', $arguments['id'])) // Attempt link to old version.
			&& !($page = DataObject::get_one('ErrorPage', '"ErrorCode" = \'404\''))   // Link to 404 page directly.
		) {
			 return; // There were no suitable matches at all.
		}
		
		if($content) {
			return sprintf('<a href="%s">%s</a>', $page->Link(), $parser->parse($content));
		} else {
			return $page->Link();
		}
	}
	
	/**
	 * Return the link for this {@link SiteTree} object, with the {@link Director::baseURL()} included.
	 *
	 * @param string $action
	 * @return string
	 */
	public function Link($action = null) {
		return Controller::join_links(Director::baseURL(), $this->RelativeLink($action));
	}
	
	/**
	 * Get the absolute URL for this page, including protocol and host.
	 *
	 * @param string $action
	 * @return string
	 */
	public function AbsoluteLink($action = null) {
		if($this->hasMethod('alternateAbsoluteLink')) {
			return $this->alternateAbsoluteLink($action);
		} else {
			return Director::absoluteURL($this->Link($action));
		}
	}
	
	/**
	 * Return the link for this {@link SiteTree} object relative to the SilverStripe root.
	 *
	 * By default, it this page is the current home page, and there is no action specified then this will return a link
	 * to the root of the site. However, if you set the $action parameter to TRUE then the link will not be rewritten
	 * and returned in its full form.
	 *
	 * @uses RootURLController::get_homepage_link()
	 * @param string $action
	 * @return string
	 */
	public function RelativeLink($action = null) {
		if($this->ParentID && self::nested_urls()) {
			$base = $this->Parent()->RelativeLink($this->URLSegment);
		} else {
			$base = $this->URLSegment;
		}
		
		if(!$action && $base == RootURLController::get_homepage_link()) {
			$base = null;
		}
		
		if(is_string($action)) {
			$action = str_replace('&', '&amp;', $action);
		} elseif($action === true) {
			$action = null;
		}
		
		return Controller::join_links($base, '/', $action);
	}
	
	/**
	 * Returns TRUE if this is the currently active page that is being used to handle a request.
	 *
	 * @return bool
	 */
	public function isCurrent() {
		return $this->ID ? $this->ID == Director::get_current_page()->ID : $this === Director::get_current_page();
	}
	
	/**
	 * Check if this page is in the currently active section (e.g. it is either current or one of it's children is
	 * currently being viewed.
	 *
	 * @return bool
	 */
	public function isSection() {
		return $this->isCurrent() || (
			Director::get_current_page() instanceof SiteTree && in_array($this->ID, Director::get_current_page()->getAncestors()->column())
		);
	}
	
	/**
	 * Return "link" or "current" depending on if this is the {@link SiteTree::isCurrent()} current page.
	 *
	 * @return string
	 */
	public function LinkOrCurrent() {
		return $this->isCurrent() ? 'current' : 'link';
	}
	
	/**
	 * Return "link" or "section" depending on if this is the {@link SiteTree::isSeciton()} current section.
	 *
	 * @return string
	 */
	public function LinkOrSection() {
		return $this->isSection() ? 'section' : 'link';
	}
	
	/**
	 * Return "link", "current" or section depending on if this page is the current page, or not on the current page but
	 * in the current section.
	 *
	 * @return string
	 */
	public function LinkingMode() {
		if($this->isCurrent()) {
			return 'current';
		} elseif($this->isSection()) {
			return 'section';
		} else {
			return 'link';
		}
	}
	
	/**
	 * Get the URL segment for this page, eg 'home'
	 *
	 * @return string The URL segment
	 */
	public function ElementName() {
		return $this->URLSegment;
	}


	/**
	 * Check if this page is in the given current section.
	 *
	 * @param string $sectionName Name of the section to check.
	 * @return boolean True if we are in the given section.
	 */
	public function InSection($sectionName) {
		$page = Director::get_current_page();
		while($page) {
			if($sectionName == $page->URLSegment)
				return true;
			$page = $page->Parent;
		}
		return false;
	}


	/**
	 * Returns comments on this page. This will only show comments that
	 * have been marked as spam if "?showspam=1" is appended to the URL.
	 *
	 * @return DataObjectSet Comments on this page.
	 */
	public function Comments() {
		$spamfilter = isset($_GET['showspam']) ? '' : "AND \"IsSpam\"=0";
		$unmoderatedfilter = Permission::check('ADMIN') ? '' : "AND \"NeedsModeration\"=0";
		$comments =  DataObject::get("PageComment", "\"ParentID\" = '" . Convert::raw2sql($this->ID) . "' $spamfilter $unmoderatedfilter", "\"Created\" DESC");
		
		return $comments ? $comments : new DataObjectSet();
	}


	/**
	 * Create a duplicate of this node. Doesn't affect joined data - create a
	 * custom overloading of this if you need such behaviour.
	 *
	 * @return SiteTree The duplicated object.
	 */
	 public function duplicate($doWrite = true) {
		$page = parent::duplicate($doWrite);
		return $page;
	}


	/**
	 * Duplicates each child of this node recursively and returns the
	 * duplicate node.
	 *
	 * @return SiteTree The duplicated object.
	 */
	public function duplicateWithChildren() {
		$clone = $this->duplicate();
		$children = $this->AllChildren();

		if($children) {
			foreach($children as $child) {
				$childClone = method_exists($child, 'duplicateWithChildren')
					? $child->duplicateWithChildren()
					: $child->duplicate();
				$childClone->ParentID = $clone->ID;
				$childClone->write();
			}
		}

		return $clone;
	}


	/**
	 * Duplicate this node and its children as a child of the node with the
	 * given ID
	 *
	 * @param int $id ID of the new node's new parent
	 */
	public function duplicateAsChild($id) {
		$newSiteTree = $this->duplicate();
		$newSiteTree->ParentID = $id;
		$newSiteTree->write();
	}
	
	/**
	 * Return a breadcrumb trail to this page. Excludes "hidden" pages
	 * (with ShowInMenus=0).
	 *
	 * @param int $maxDepth The maximum depth to traverse.
	 * @param boolean $unlinked Do not make page names links
	 * @param string $stopAtPageType ClassName of a page to stop the upwards traversal.
	 * @param boolean $showHidden Include pages marked with the attribute ShowInMenus = 0 
	 * @return string The breadcrumb trail.
	 */
	public function Breadcrumbs($maxDepth = 20, $unlinked = false, $stopAtPageType = false, $showHidden = false) {
		$page = $this;
		$parts = array();
		$i = 0;
		while(
			$page  
 			&& (!$maxDepth || sizeof($parts) < $maxDepth) 
 			&& (!$stopAtPageType || $page->ClassName != $stopAtPageType)
 		) {
			if($showHidden || $page->ShowInMenus || ($page->ID == $this->ID)) { 
				if($page->URLSegment == 'home') $hasHome = true;
				if(($page->ID == $this->ID) || $unlinked) {
				 	$parts[] = Convert::raw2xml($page->Title);
				} else {
					$parts[] = ("<a href=\"" . $page->Link() . "\">" . Convert::raw2xml($page->Title) . "</a>"); 
				}
			}
			$page = $page->Parent;
		}

		return implode(self::$breadcrumbs_delimiter, array_reverse($parts));
	}

	/**
	 * Make this page a child of another page.
	 * 
	 * If the parent page does not exist, resolve it to a valid ID
	 * before updating this page's reference.
	 *
	 * @param SiteTree|int $item Either the parent object, or the parent ID
	 */
	public function setParent($item) {
		if(is_object($item)) {
			if (!$item->exists()) $item->write();
			$this->setField("ParentID", $item->ID);
		} else {
			$this->setField("ParentID", $item);
		}
	}
 	
	/**
	 * Get the parent of this page.
	 *
	 * @return SiteTree Parent of this page.
	 */
	public function getParent() {
		if ($this->getField("ParentID")) {
			return DataObject::get_one("SiteTree", "\"SiteTree\".\"ID\" = " . $this->getField("ParentID"));
		}
	}

	/**
	 * Return a string of the form "parent - page" or
	 * "grandparent - parent - page".
	 *
	 * @param int $level The maximum amount of levels to traverse.
	 * @param string $seperator Seperating string
	 * @return string The resulting string
	 */
	function NestedTitle($level = 2, $separator = " - ") {
		$item = $this;
		while($item && $level > 0) {
			$parts[] = $item->Title;
			$item = $item->Parent;
			$level--;
		}
		return implode($separator, array_reverse($parts));
	}

	/**
	 * This function should return true if the current user can add children
	 * to this page. It can be overloaded to customise the security model for an
	 * application.
	 *
	 * Returns true if the member is allowed to do the given action.
	 *
	 * @uses DataObjectDecorator->can()
	 *
	 * @param string $perm The permission to be checked, such as 'View'.
	 * @param Member $member The member whose permissions need checking.
	 *                       Defaults to the currently logged in user.
	 *
	 * @return boolean True if the the member is allowed to do the given
	 *                 action.
	 *
	 * @todo Check we get a endless recursion if we use parent::can()
	 */
	function can($perm, $member = null) {
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
			$member = Member::currentUserID();
		}

		if($member && Permission::checkMember($member, "ADMIN")) return true;
		
		if(method_exists($this, 'can' . ucfirst($perm))) {
			$method = 'can' . ucfirst($perm);
			return $this->$method($member);
		}
		
		// DEPRECATED 2.3: Use can()
		$results = $this->extend('alternateCan', $member);
		if($results && is_array($results)) if(!min($results)) return false;
		
		$results = $this->extend('can', $member);
		if($results && is_array($results)) if(!min($results)) return false;

		return true;
	}


	/**
	 * This function should return true if the current user can add children
	 * to this page. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - alternateCanAddChildren() on a decorator returns FALSE
	 * - canEdit() is not granted
	 * - There are no classes defined in {@link $allowed_children}
	 * 
	 * @uses SiteTreeDecorator->canAddChildren()
	 * @uses canEdit()
	 * @uses $allowed_children
	 *
	 * @return boolean True if the current user can add children.
	 */
	public function canAddChildren($member = null) {
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
			$member = Member::currentUserID();
		}

		if($member && Permission::checkMember($member, "ADMIN")) return true;
		
		// DEPRECATED 2.3: use canAddChildren() instead
		$results = $this->extend('alternateCanAddChildren', $member);
		if($results && is_array($results)) if(!min($results)) return false;
		
		$results = $this->extend('canAddChildren', $member);
		if($results && is_array($results)) if(!min($results)) return false;
		
		return $this->canEdit($member) && $this->stat('allowed_children') != 'none';
	}


	/**
	 * This function should return true if the current user can view this
	 * page. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - canView() on any decorator returns FALSE
	 * - "CanViewType" directive is set to "Inherit" and any parent page return false for canView()
	 * - "CanViewType" directive is set to "LoggedInUsers" and no user is logged in
	 * - "CanViewType" directive is set to "OnlyTheseUsers" and user is not in the given groups
	 *
	 * @uses DataObjectDecorator->canView()
	 * @uses ViewerGroups()
	 *
	 * @return boolean True if the current user can view this page.
	 */
	public function canView($member = null) {
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
			$member = Member::currentUserID();
		}

		// admin override
		if($member && Permission::checkMember($member, array("ADMIN", "SITETREE_VIEW_ALL"))) return true;
		
		// DEPRECATED 2.3: use canView() instead
		$results = $this->extend('alternateCanView', $member);
		if($results && is_array($results)) if(!min($results)) return false;
		
		// decorated access checks
		$results = $this->extend('canView', $member);
		if($results && is_array($results)) if(!min($results)) return false;
		
		// check for empty spec
		if(!$this->CanViewType || $this->CanViewType == 'Anyone') return true;

		// check for inherit
		if($this->CanViewType == 'Inherit') {
			if($this->ParentID) return $this->Parent()->canView($member);
			else return true;
		}
		
		// check for any logged-in users
		if($this->CanViewType == 'LoggedInUsers' && $member) {
			return true;
		}
		
		// check for specific groups
		if($member && is_numeric($member)) $member = DataObject::get_by_id('Member', $member);
		if(
			$this->CanViewType == 'OnlyTheseUsers' 
			&& $member 
			&& $member->inGroups($this->ViewerGroups())
		) return true;
		
		return false;
	}

	/**
	 * This function should return true if the current user can delete this
	 * page. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - canDelete() returns FALSE on any decorator
	 * - canEdit() returns FALSE
	 * - any descendant page returns FALSE for canDelete()
	 * 
	 * @uses canDelete()
	 * @uses DataObjectDecorator->canDelete()
	 * @uses canEdit()
	 *
	 * @param Member $member
	 * @return boolean True if the current user can delete this page.
	 */
	public function canDelete($member = null) {
		if($member instanceof Member) $memberID = $member->ID;
		else if(is_numeric($member)) $memberID = $member;
		else $memberID = Member::currentUserID();
		
		if($memberID && Permission::checkMember($memberID, array("ADMIN", "SITETREE_EDIT_ALL"))) {
			return true;
		}
		
		// DEPRECATED 2.3: use canDelete() instead
		$results = $this->extend('alternateCanDelete', $memberID);
		if($results && is_array($results)) if(!min($results)) return false;
		
		// decorated access checks
		$results = $this->extend('canDelete', $memberID);
		if($results && is_array($results)) if(!min($results)) return false;
		
		// Check cache (the can_edit_multiple call below will also do this, but this is quicker)
		if(isset(self::$cache_permissions['delete'][$this->ID])) {
			return self::$cache_permissions['delete'][$this->ID];
		}
		
		// Regular canEdit logic is handled by can_edit_multiple
		$results = self::can_delete_multiple(array($this->ID), $memberID);
		return $results[$this->ID];
	}

	/**
	 * This function should return true if the current user can create new
	 * pages of this class. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - canCreate() returns FALSE on any decorator
	 * - $can_create is set to FALSE and the site is not in "dev mode"
	 * 
	 * Use {@link canAddChildren()} to control behaviour of creating children under this page.
	 * 
	 * @uses $can_create
	 * @uses DataObjectDecorator->canCreate()
	 *
	 * @param Member $member
	 * @return boolean True if the current user can create pages on this class.
	 */
	public function canCreate($member = null) {
		if($this->stat('single_instance_only') && DataObject::get_one($this->class)) return false;
		
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
			$member = Member::currentUserID();
		}

		if($member && Permission::checkMember($member, "ADMIN")) return true;
		
		// DEPRECATED 2.3: use canCreate() instead
		$results = $this->extend('alternateCanCreate', $member);
		if($results && is_array($results)) if(!min($results)) return false;
		
		// decorated permission checks
		$results = $this->extend('canCreate', $member);
		if($results && is_array($results)) if(!min($results)) return false;
		
		return $this->stat('can_create') != false || Director::isDev();
	}

	/**
	 * This function should return true if the current user can edit this
	 * page. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - canEdit() on any decorator returns FALSE
	 * - canView() return false
	 * - "CanEditType" directive is set to "Inherit" and any parent page return false for canEdit()
	 * - "CanEditType" directive is set to "LoggedInUsers" and no user is logged in or doesn't have the CMS_Access_CMSMAIN permission code
	 * - "CanEditType" directive is set to "OnlyTheseUsers" and user is not in the given groups
	 * 
	 * @uses canView()
	 * @uses EditorGroups()
	 * @uses DataObjectDecorator->canEdit()
	 *
	 * @param Member $member Set to FALSE if you want to explicitly test permissions without a valid user (useful for unit tests)
	 * @return boolean True if the current user can edit this page.
	 */
	public function canEdit($member = null) {
		if($member instanceof Member) $memberID = $member->ID;
		else if(is_numeric($member)) $memberID = $member;
		else $memberID = Member::currentUserID();
		
		if($memberID && Permission::checkMember($memberID, array("ADMIN", "SITETREE_EDIT_ALL"))) return true;

		// DEPRECATED 2.3: use canEdit() instead
		$results = $this->extend('alternateCanEdit', $memberID);
		if($results && is_array($results)) if(!min($results)) return false;
		
		// decorated access checks
		$results = $this->extend('canEdit', $memberID);
		if($results && is_array($results)) if(!min($results)) return false;
		
		// Check cache (the can_edit_multiple call below will also do this, but this is quicker)
		if(isset(self::$cache_permissions['edit'][$this->ID])) {
			return self::$cache_permissions['edit'][$this->ID];
		}
		
		// Regular canEdit logic is handled by can_edit_multiple
		$results = self::can_edit_multiple(array($this->ID), $memberID);
		
		return $results[$this->ID];
	}

	/**
	 * This function should return true if the current user can publish this
	 * page. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - canPublish() on any decorator returns FALSE
	 * - canEdit() returns FALSE
	 * 
	 * @uses SiteTreeDecorator->canPublish()
	 *
	 * @param Member $member
	 * @return boolean True if the current user can publish this page.
	 */
	public function canPublish($member = null) {
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) $member = Member::currentUser();
		
		if($member && Permission::checkMember($member, "ADMIN")) return true;
		
		// DEPRECATED 2.3: use canPublish() instead
		$results = $this->extend('alternateCanPublish', $member);
		if($results && is_array($results)) if(!min($results)) return false;
		
		// If we have a result, then that means at least one decorator specified alternateCanPublish
		// Allow the permission check only if *all* voting decorators allow it.
		$results = $this->extend('canPublish', $member);
		if($results && is_array($results)) if(!min($results)) return false;

		// Normal case
		return $this->canEdit($member);
	}


	/**
	 * Pre-populate the cache of canEdit, canView, canDelete, canPublish permissions.
	 * This method will use the static can_(perm)_multiple method for efficiency.
	 */
	static function prepopuplate_permission_cache($permission = 'edit', $ids) {
		$methodName = "can_{$permission}_multiple";
		if(is_callable(array('SiteTree', $methodName))) {
			$permissionValues = call_user_func(array('SiteTree', $methodName), $ids, 
				Member::currentUserID(), false);
				
			if(!isset(self::$cache_permissions[$permission])) {
				self::$cache_permissions[$permission] = array();
			}
			
			self::$cache_permissions[$permission] = $permissionValues 
				+ self::$cache_permissions[$permission];
			
		} else {
			user_error("SiteTree::prepopuplate_permission_cache passed bad permission '$permission'"
				, E_USER_WARNING);
		}
	}
	
	/**
	 * Get the 'can edit' information for a number of SiteTree pages.
	 * 
	 * @param An array of IDs of the SiteTree pages to look up.
	 * @param useCached Return values from the permission cache if they exist.
	 * @return A map where the IDs are keys and the values are booleans stating whether the given
	 * page can be edited.
	 */
	static function can_edit_multiple($ids, $memberID, $useCached = true) {
		// Sanitise the IDs
		$ids = array_filter($ids, 'is_numeric');

		// Default result: nothing editable
		$result = array_fill_keys($ids, false);
		if($ids) {

			// Look in the cache for values
			if($useCached && isset(self::$cache_permissions['edit'])) {
				$cachedValues = array_intersect_key(self::$cache_permissions['edit'], $result);
			
				// If we can't find everything in the cache, then look up the remainder separately
				$uncachedValues = array_diff_key($result, self::$cache_permissions['edit']);
				if($uncachedValues) {
					$cachedValues = self::can_edit_multiple(array_keys($uncachedValues), $memberID, false)
						+ $cachedValues;
				}
				return $cachedValues;
			}
		
			// If a member doesn't have CMS_ACCESS_CMSMain permission then they can't edit anything
			if(!$memberID || !Permission::checkMember($memberID, 'CMS_ACCESS_CMSMain')) {
				return $result;
			}

			$SQL_idList = implode($ids, ", ");

			// if page can't be viewed, don't grant edit permissions
			// to do - implement can_view_multiple(), so this can be enabled
			//$ids = array_keys(array_filter(self::can_view_multiple($ids, $memberID)));
		
			// Get the groups that the given member belongs to
			$groupIDs = DataObject::get_by_id('Member', $memberID)->Groups()->column("ID");
			$SQL_groupList = implode(", ", $groupIDs);

			$combinedStageResult = array();
			
			foreach(array('Stage', 'Live') as $stage) {
				// Get the uninherited permissions
				$uninheritedPermissions = Versioned::get_by_stage("SiteTree", $stage, "(\"CanEditType\" = 'LoggedInUsers' OR
					(\"CanEditType\" = 'OnlyTheseUsers' AND \"SiteTree_EditorGroups\".\"SiteTreeID\" IS NOT NULL))
					AND \"SiteTree\".\"ID\" IN ($SQL_idList)",
					"",
					"LEFT JOIN \"SiteTree_EditorGroups\" 
					ON \"SiteTree_EditorGroups\".\"SiteTreeID\" = \"SiteTree\".\"ID\"
					AND \"SiteTree_EditorGroups\".\"GroupID\" IN ($SQL_groupList)");

				if($uninheritedPermissions) {
					// Set all the relevant items in $result to true
					$result = array_fill_keys($uninheritedPermissions->column('ID'), true) + $result;
				}

				// Get permissions that are inherited
				$potentiallyInherited = Versioned::get_by_stage("SiteTree", $stage, "\"CanEditType\" = 'Inherit'
					AND \"SiteTree\".\"ID\" IN ($SQL_idList)");

				if($potentiallyInherited) {
					// Group $potentiallyInherited by ParentID; we'll look at the permission of all those
					// parents and then see which ones the user has permission on
					foreach($potentiallyInherited as $item) {
						$groupedByParent[$item->ParentID][] = $item->ID;
					}

					$actuallyInherited = self::can_edit_multiple(array_keys($groupedByParent), $memberID);
					if($actuallyInherited) {
						$parentIDs = array_keys(array_filter($actuallyInherited));
						foreach($parentIDs as $parentID) {
							// Set all the relevant items in $result to true
							$result = array_fill_keys($groupedByParent[$parentID], true) + $result;
						}
					}
				}
			}

			$combinedStageResult = $combinedStageResult + $result;
		}

		return $combinedStageResult;
		
		
		/*
		// check for empty spec
		if(!$this->CanEditType || $this->CanEditType == 'Anyone') return true;
		
		// check for inherit
		if($this->CanEditType == 'Inherit') {
			if($this->ParentID) return $this->Parent()->canEdit($member);
			else return ($member && Permission::checkMember($member, 'CMS_ACCESS_CMSMain'));
		}

		// check for any logged-in users
		if($this->CanEditType == 'LoggedInUsers' && ) return true;
		
		// check for specific groups
		if($this->CanEditType == 'OnlyTheseUsers' && $member && $member->inGroups($this->EditorGroups())) return true;
		
		return false;
		*/
		
	}

	/**
	 * Get the 'can edit' information for a number of SiteTree pages.
	 * @param An array of IDs of the SiteTree pages to look up.
	 * @param useCached Return values from the permission cache if they exist.
	 */
	static function can_delete_multiple($ids, $memberID, $useCached = true) {
		$deletable = array();
		
		// Look in the cache for values
		if($useCached && isset(self::$cache_permissions['delete'])) {
			$cachedValues = array_intersect_key(self::$cache_permissions['delete'], $result);
			
			// If we can't find everything in the cache, then look up the remainder separately
			$uncachedValues = array_diff_key($result, self::$cache_permissions['delete']);
			if($uncachedValues) {
				$cachedValues = self::can_delete_multiple(array_keys($uncachedValues), $memberID, false)
					+ $cachedValues;
			}
			return $cachedValues;
		}

		// You can only delete pages that you can edit
		$editableIDs = array_keys(array_filter(self::can_edit_multiple($ids, $memberID)));
		if($editableIDs) {
			$idList = implode(",", $editableIDs);
		
			// You can only delete pages whose children you can delete
			$childRecords = DataObject::get("SiteTree", "\"ParentID\" IN ($idList)");
			if($childRecords) {
				$children = $childRecords->map("ID", "ParentID");

				// Find out the children that can be deleted
				$deletableChildren = self::can_delete_multiple(array_keys($children), $memberID);
				
				// Get a list of all the parents that have no undeletable children
				$deletableParents = array_fill_keys($editableIDs, true);
				foreach($deletableChildren as $id => $canDelete) {
					if(!$canDelete) unset($deletableParents[$children[$id]]);
				}

				// Use that to filter the list of deletable parents that have children
				$deletableParents = array_keys($deletableParents);

				// Also get the $ids that don't have children
				$parents = array_unique($children);
				$deletableLeafNodes = array_diff($editableIDs, $parents);

				// Combine the two
				$deletable = array_merge($deletableParents, $deletableLeafNodes);

			} else {
				$deletable = $editableIDs;
			}
		} else {
			$deletable = array();
		}
		
		// Convert the array of deletable IDs into a map of the original IDs with true/false as the
		// value
		return array_fill_keys($deletable, true) + array_fill_keys($ids, false);
	}

	/**
	 * Collate selected descendants of this page.
	 *
	 * {@link $condition} will be evaluated on each descendant, and if it is
	 * succeeds, that item will be added to the $collator array.
	 *
	 * @param string $condition The PHP condition to be evaluated. The page
	 *                          will be called $item
	 * @param array $collator An array, passed by reference, to collect all
	 *                        of the matching descendants.
	 */
	public function collateDescendants($condition, &$collator) {
		if($children = $this->Children()) {
			foreach($children as $item) {
				if(eval("return $condition;")) $collator[] = $item;
				$item->collateDescendants($condition, $collator);
			}
			return true;
		}
	}


	/**
	 * Return the title, description, keywords and language metatags.
	 * 
	 * @todo Move <title> tag in separate getter for easier customization and more obvious usage
	 * 
	 * @param boolean|string $includeTitle Show default <title>-tag, set to false for custom templating
	 * @param boolean $includeTitle Show default <title>-tag, set to false for
	 *                              custom templating
	 * @return string The XHTML metatags
	 */
	public function MetaTags($includeTitle = true) {
		$tags = "";
		if($includeTitle === true || $includeTitle == 'true') {
			$tags .= "<title>" . Convert::raw2xml(($this->MetaTitle)
				? $this->MetaTitle
				: $this->Title) . "</title>\n";
		}
		$version = new SapphireInfo();

		$tags .= "<meta name=\"generator\" http-equiv=\"generator\" content=\"SilverStripe - http://www.silverstripe.com\" />\n";

		$charset = ContentNegotiator::get_encoding();
		$tags .= "<meta http-equiv=\"Content-type\" content=\"text/html; charset=$charset\" />\n";
		if($this->MetaKeywords) {
			$tags .= "<meta name=\"keywords\" http-equiv=\"keywords\" content=\"" .
				Convert::raw2att($this->MetaKeywords) . "\" />\n";
		}
		if($this->MetaDescription) {
			$tags .= "<meta name=\"description\" http-equiv=\"description\" content=\"" .
				Convert::raw2att($this->MetaDescription) . "\" />\n";
		}
		if($this->ExtraMeta) { 
			$tags .= $this->ExtraMeta . "\n";
		} 
		
		// get the "long" lang name suitable for the HTTP content-language flag (with hyphens instead of underscores)
		$currentLang = ($this->hasExtension('Translatable')) ? Translatable::get_current_locale() : i18n::get_locale();
		$tags .= "<meta http-equiv=\"Content-Language\" content=\"". i18n::convert_rfc1766($currentLang) ."\"/>\n";
		
		// DEPRECATED 2.3: Use MetaTags
		$this->extend('updateMetaTags', $tags);
		
		$this->extend('MetaTags', $tags);

		return $tags;
	}


	/**
	 * Returns the object that contains the content that a user would
	 * associate with this page.
	 *
	 * Ordinarily, this is just the page itself, but for example on
	 * RedirectorPages or VirtualPages ContentSource() will return the page
	 * that is linked to.
	 *
	 * @return SiteTree The content source.
	 */
	public function ContentSource() {
		return $this;
	}


	/**
	 * Add default records to database.
	 *
	 * This function is called whenever the database is built, after the
	 * database tables have all been created. Overload this to add default
	 * records when the database is built, but make sure you call
	 * parent::requireDefaultRecords().
	 */
	function requireDefaultRecords() {
		parent::requireDefaultRecords();
		
		// default pages
		if($this->class == 'SiteTree') {
			if(!DataObject::get_one("SiteTree", "\"URLSegment\" = 'home'")) {
				$homepage = new Page();

				$homepage->Title = _t('SiteTree.DEFAULTHOMETITLE', 'Home');
				$homepage->Content = _t('SiteTree.DEFAULTHOMECONTENT', '<p>Welcome to SilverStripe! This is the default homepage. You can edit this page by opening <a href="admin/">the CMS</a>. You can now access the <a href="http://doc.silverstripe.com">developer documentation</a>, or begin <a href="http://doc.silverstripe.com/doku.php?id=tutorials">the tutorials.</a></p>');
				$homepage->URLSegment = "home";
				$homepage->Status = "Published";
				$homepage->write();
				$homepage->publish("Stage", "Live");
				$homepage->flushCache();
				Database::alteration_message("Home page created","created");		
			}

			if(DB::query("SELECT COUNT(*) FROM \"SiteTree\"")->value() == 1) {
				$aboutus = new Page();
				$aboutus->Title = _t('SiteTree.DEFAULTABOUTTITLE', 'About Us');
				$aboutus->Content = _t('SiteTree.DEFAULTABOUTCONTENT', '<p>You can fill this page out with your own content, or delete it and create your own pages.<br /></p>');
				$aboutus->URLSegment = "about-us";
				$aboutus->Status = "Published";
				$aboutus->write();
				$aboutus->publish("Stage", "Live");
				Database::alteration_message("About Us created","created");

				$contactus = new Page();
				$contactus->Title = _t('SiteTree.DEFAULTCONTACTTITLE', 'Contact Us');
				$contactus->Content = _t('SiteTree.DEFAULTCONTACTCONTENT', '<p>You can fill this page out with your own content, or delete it and create your own pages.<br /></p>');
				$contactus->URLSegment = "contact-us";
				$contactus->Status = "Published";
				$contactus->write();
				$contactus->publish("Stage", "Live");

				$contactus->flushCache();
			}
		}
		
		// schema migration
		// @todo Move to migration task once infrastructure is implemented
		if($this->class == 'SiteTree') {
			$conn = DB::getConn();
			// only execute command if fields haven't been renamed to _obsolete_<fieldname> already by the task
			if(array_key_exists('Viewers', $conn->fieldList('SiteTree'))) {
				$task = new UpgradeSiteTreePermissionSchemaTask();
				$task->run(new HTTPRequest('GET','/'));
			}
		}
	}


	//------------------------------------------------------------------------------------//

	protected function onBeforeWrite() {
		// If Sort hasn't been set, make this page come after it's siblings
		if(!$this->Sort && $this->ParentID) {
			$this->Sort = DB::query("SELECT MAX(\"Sort\") + 1 FROM \"SiteTree\" WHERE \"ParentID\" = $this->ParentID")->value();
		}

		// If there is no URLSegment set, generate one from Title
		if((!$this->URLSegment || $this->URLSegment == 'new-page') && $this->Title) {
			$this->URLSegment = $this->generateURLSegment($this->Title);
		} else if($this->isChanged('URLSegment')) {
			// Make sure the URLSegment is valid for use in a URL
			$segment = ereg_replace('[^A-Za-z0-9]+','-',$this->URLSegment);
			$segment = ereg_replace('-+','-',$segment);
			
			// If after sanitising there is no URLSegment, give it a reasonable default
			if(!$segment) {
				$segment = "page-$this->ID";
			}
			$this->URLSegment = $segment;
		}
		
		DataObject::set_context_obj($this);
		
		// Ensure that this object has a non-conflicting URLSegment value.
		$count = 2;
		while(!$this->validURLSegment()) {
			$this->URLSegment = preg_replace('/-[0-9]+$/', null, $this->URLSegment) . '-' . $count;
			$count++;
		}
		
		DataObject::set_context_obj(null);
		
		parent::onBeforeWrite();
	}
	
	function onAfterWrite() {
		// Need to flush cache to avoid outdated versionnumber references
		$this->flushCache();
		
		// Update any virtual pages that might need updating
		$linkedPages = DataObject::get("VirtualPage", "\"CopyContentFromID\" = $this->ID");
		if($linkedPages) foreach($linkedPages as $page) {
			$page->copyFrom($page->CopyContentFrom());
			$page->write();
		}
		
		parent::onAfterWrite();
	}
	
	function onBeforeDelete() {
		parent::onBeforeDelete();
		
		// If deleting this page, delete all its children.
		if($children = $this->Children()) {
			foreach($children as $child) {
				$child->delete();
			}
		}
	}
	
	
	function onAfterDelete() {
		// Need to flush cache to avoid outdated versionnumber references
		$this->flushCache();
		
		parent::onAfterDelete();
	}
	
	/**
	 * Returns TRUE if this object has a URLSegment value that does not conflict with any other objects. This methods
	 * checks for:
	 *   - A page with the same URLSegment that has a conflict.
	 *   - Conflicts with actions on the parent page.
	 *   - A conflict caused by a root page having the same URLSegment as a class name.
	 *   - Conflicts with action-specific templates on the parent page.
	 *
	 * @return bool
	 */
	public function validURLSegment() {
		if(self::nested_urls() && $parent = $this->Parent()) {
			if($this->URLSegment == 'index') return false;
			
			if($controller = ModelAsController::controller_for($parent)) {
				$actions = Object::combined_static($controller->class, 'allowed_actions', 'RequestHandler');
				
				// check for a conflict with an entry in $allowed_actions
				if(is_array($actions)) {
					if(array_key_exists($this->URLSegment, $actions) || in_array($this->URLSegment, $actions)) {
						return false;
					}
				}
				
				// check for a conflict with an action-specific template
				if($controller->hasMethod('hasActionTemplate') && $controller->hasActionTemplate($this->URLSegment)) {
					return false;
				}
			}
		}
		
		if(!self::nested_urls() || !$this->ParentID) {
			if(class_exists($this->URLSegment) && is_subclass_of($this->URLSegment, 'RequestHandler')) return false;
		}
		
		$IDFilter     = ($this->ID) ? "AND \"SiteTree\".\"ID\" <> $this->ID" :  null;
		$parentFilter = null;
		
		if(self::nested_urls()) {
			if($this->ParentID) {
				$parentFilter = " AND \"SiteTree\".\"ParentID\" = $this->ParentID";
			} else {
				$parentFilter = ' AND "SiteTree"."ParentID" = 0';
			}
		}
		
		return DB::query (
			"SELECT COUNT(ID) FROM \"SiteTree\" WHERE \"URLSegment\" = '$this->URLSegment' $IDFilter $parentFilter"
		)->value() < 1;
	}
	
	/**
	 * Generate a URL segment based on the title provided.
	 * @param string $title Page title.
	 * @return string Generated url segment
	 */
	function generateURLSegment($title){
		$t = strtolower($title);
		$t = str_replace('&amp;','-and-',$t);
		$t = str_replace('&','-and-',$t);
		$t = ereg_replace('[^A-Za-z0-9]+','-',$t);
		$t = ereg_replace('-+','-',$t);
		if(!$t || $t == '-' || $t == '-1') {
			$t = "page-$this->ID";
		}
		return trim($t, '-');
	}
	
	/**
	 * @deprecated 2.4 Use {@link SiteTree::get_by_link()}.
	 */
	public static function get_by_url($link) {
		user_error (
			'SiteTree::get_by_url() is deprecated, please use SiteTree::get_by_link()', E_USER_NOTICE
		);
		
		return self::get_by_link($link);
	}
	
	/**
	 * Returns a FieldSet with which to create the CMS editing form.
	 *
	 * You can override this in your child classes to add extra fields - first
	 * get the parent fields using parent::getCMSFields(), then use
	 * addFieldToTab() on the FieldSet.
	 *
	 * @return FieldSet The fields to be displayed in the CMS.
	 */
	function getCMSFields() {
		require_once("forms/Form.php");
		Requirements::javascript(THIRDPARTY_DIR . "/prototype.js");
		Requirements::javascript(THIRDPARTY_DIR . "/behaviour.js");
		Requirements::javascript(THIRDPARTY_DIR . "/behaviour_improvements.js");
		Requirements::javascript(CMS_DIR . "/javascript/SitetreeAccess.js");
		Requirements::add_i18n_javascript(SAPPHIRE_DIR . '/javascript/lang');
		Requirements::javascript(SAPPHIRE_DIR . '/javascript/UpdateURL.js');

		// Status / message
		// Create a status message for multiple parents
		if($this->ID && is_numeric($this->ID)) {
			$linkedPages = DataObject::get("VirtualPage", "\"CopyContentFromID\" = $this->ID");
		}
		
		$parentPageLinks = array();

		if(isset($linkedPages)) {
			foreach($linkedPages as $linkedPage) {
				$parentPage = $linkedPage->Parent;
				if($parentPage) {
					if($parentPage->ID) {
						$parentPageLinks[] = "<a class=\"cmsEditlink\" href=\"admin/show/$linkedPage->ID\">{$parentPage->Title}</a>";
					} else {
						$parentPageLinks[] = "<a class=\"cmsEditlink\" href=\"admin/show/$linkedPage->ID\">" .
							_t('SiteTree.TOPLEVEL', 'Site Content (Top Level)') .
							"</a>";
					}
				}
			}

			$lastParent = array_pop($parentPageLinks);
			$parentList = "'$lastParent'";

			if(count($parentPageLinks) > 0) {
				$parentList = "'" . implode("', '", $parentPageLinks) . "' and "
					. $parentList;
			}

			$statusMessage[] = sprintf(
				_t('SiteTree.APPEARSVIRTUALPAGES', "This content also appears on the virtual pages in the %s sections."),
				$parentList
			);
		}

		if($this->HasBrokenLink || $this->HasBrokenFile) {
			$statusMessage[] = _t('SiteTree.HASBROKENLINKS', "This page has broken links.");
		}

		$message = "STATUS: $this->Status<br />";
		if(isset($statusMessage)) {
			$message .= "NOTE: " . implode("<br />", $statusMessage);
		}
		
		$backLinksNote = '';
		$backLinksTable = new LiteralField('BackLinksNote', '<p>' . _t('NOBACKLINKEDPAGES', 'There are no pages linked to this page.') . '</p>');
		
		// Create a table for showing pages linked to this one
		if($this->BackLinkTracking() && $this->BackLinkTracking()->Count() > 0) {
			$backLinksNote = new LiteralField('BackLinksNote', '<p>' . _t('SiteTree.PAGESLINKING', 'The following pages link to this page:') . '</p>');
			$backLinksTable = new TableListField(
				'BackLinkTracking',
				'SiteTree',
				array(
					'Title' => 'Title'
				),
				'"ChildID" = ' . $this->ID,
				'',
				'LEFT JOIN "SiteTree_LinkTracking" ON "SiteTree"."ID" = "SiteTree_LinkTracking"."SiteTreeID"'
			);
			$backLinksTable->setFieldFormatting(array(
				'Title' => '<a href=\"admin/show/$ID\">$Title</a>'
			));
			$backLinksTable->setPermissions(array(
				'show',
				'export'
			));
		}
		
		// Lay out the fields
		$fields = new FieldSet(
			// Add a field with a bit of metadata for concurrent editing. The fact that we're using
			// non-standard attributes does not really matter, all modern UA's just ignore em.
			new LiteralField("SiteTree_Alert", '<div deletedfromstage="'.((int) $this->getIsDeletedFromStage()).'" id="SiteTree_Alert"></div>'),
			new TabSet("Root",
				$tabContent = new TabSet('Content',
					$tabMain = new Tab('Main',
						new TextField("Title", $this->fieldLabel('Title')),
						new TextField("MenuTitle", $this->fieldLabel('MenuTitle')),
						new HtmlEditorField("Content", _t('SiteTree.HTMLEDITORTITLE', "Content", PR_MEDIUM, 'HTML editor title')),
						new HiddenField("Version", "Version", $this->Version)
					),
					$tabMeta = new Tab('Metadata',
						new FieldGroup(_t('SiteTree.URL', "URL"),
							new LabelField('BaseUrlLabel',Controller::join_links (
								Director::absoluteBaseURL(),
								(self::nested_urls() && $this->ParentID ? $this->Parent->RelativeLink(true) : null)
							)),
							new UniqueRestrictedTextField("URLSegment",
								"URLSegment",
								"SiteTree",
								_t('SiteTree.VALIDATIONURLSEGMENT1', "Another page is using that URL. URL must be unique for each page"),
								"[^A-Za-z0-9-]+",
								"-",
								_t('SiteTree.VALIDATIONURLSEGMENT2', "URLs can only be made up of letters, digits and hyphens."),
								"",
								"",
								"",
								50
							),
							new LabelField('TrailingSlashLabel',"/")
						),
						new LiteralField('LinkChangeNote', self::nested_urls() && count($this->Children()) ?
							'<p>' . $this->fieldLabel('LinkChangeNote'). '</p>' : null
						),
						new HeaderField('MetaTagsHeader',$this->fieldLabel('MetaTagsHeader')),
						new TextField("MetaTitle", $this->fieldLabel('MetaTitle')),
						new TextareaField("MetaKeywords", $this->fieldLabel('MetaKeywords'), 1),
						new TextareaField("MetaDescription", $this->fieldLabel('MetaDescription')),
						new TextareaField("ExtraMeta",$this->fieldLabel('ExtraMeta'))
					)
				),
				$tabBehaviour = new Tab('Behaviour',
					new DropdownField(
						"ClassName", 
						$this->fieldLabel('ClassName'), 
						$this->getClassDropdown()
					),
					
					new OptionsetField("ParentType", "Page location", array(
						"root" => _t("SiteTree.PARENTTYPE_ROOT", "Top-level page"),
						"subpage" => _t("SiteTree.PARENTTYPE_SUBPAGE", "Sub-page underneath a parent page (choose below)"),
					)),
					new TreeDropdownField("ParentID", $this->fieldLabel('ParentID'), 'SiteTree'),
					
					new CheckboxField("ShowInMenus", $this->fieldLabel('ShowInMenus')),
					new CheckboxField("ShowInSearch", $this->fieldLabel('ShowInSearch')),
					/*, new TreeMultiselectField("MultipleParents", "Page appears within", "SiteTree")*/
					new CheckboxField("ProvideComments", $this->fieldLabel('ProvideComments')),
					new LiteralField(
						"HomepageForDomainInfo", 
						"<p>" . 
							_t('SiteTree.NOTEUSEASHOMEPAGE', 
							"Use this page as the 'home page' for the following domains: 
							(separate multiple domains with commas)") .
						"</p>"
					),
					new TextField(
						"HomepageForDomain",
						_t('SiteTree.HOMEPAGEFORDOMAIN', "Domain(s)", PR_MEDIUM, 'Listing domains that should be used as homepage')
					)
				),
				$tabToDo = new Tab($this->ToDo ? 'To-do **' : 'To-do',
					new LiteralField("ToDoHelp", _t('SiteTree.TODOHELP', "<p>You can use this to keep track of work that needs to be done to the content of your site.  To see all your pages with to do information, open the 'Site Reports' window on the left and select 'To Do'</p>")),
					new TextareaField("ToDo", "")
				),
				$tabReports = new TabSet('Reports',
					$tabBacklinks = new Tab('Backlinks',
						$backLinksNote,
						$backLinksTable
					)
				),
				$tabAccess = new Tab('Access',
					new HeaderField('WhoCanViewHeader',_t('SiteTree.ACCESSHEADER', "Who can view this page?"), 2),
					$viewersOptionsField = new OptionsetField(
						"CanViewType", 
						""
					),
					$viewerGroupsField = new TreeMultiselectField("ViewerGroups", $this->fieldLabel('ViewerGroups')),
					new HeaderField('WhoCanEditHeader',_t('SiteTree.EDITHEADER', "Who can edit this page?"), 2),
					$editorsOptionsField = new OptionsetField(
						"CanEditType", 
						""
					),
					$editorGroupsField = new TreeMultiselectField("EditorGroups", $this->fieldLabel('EditorGroups'))
				)
			)
			//new NamedLabelField("Status", $message, "pageStatusMessage", true)
		);
		
		$viewersOptionsSource = array();
		if($this->Parent()->ID || $this->CanViewType == 'Inherit') $viewersOptionsSource["Inherit"] = _t('SiteTree.INHERIT', "Inherit from parent page");
		$viewersOptionsSource["Anyone"] = _t('SiteTree.ACCESSANYONE', "Anyone");
		$viewersOptionsSource["LoggedInUsers"] = _t('SiteTree.ACCESSLOGGEDIN', "Logged-in users");
		$viewersOptionsSource["OnlyTheseUsers"] = _t('SiteTree.ACCESSONLYTHESE', "Only these people (choose from list)");
		$viewersOptionsField->setSource($viewersOptionsSource);
		
		$editorsOptionsSource = array();
		if($this->Parent()->ID || $this->CanEditType == 'Inherit') $editorsOptionsSource["Inherit"] = _t('SiteTree.INHERIT', "Inherit from parent page");
		$editorsOptionsSource["LoggedInUsers"] = _t('SiteTree.EDITANYONE', "Anyone who can log-in to the CMS");
		$editorsOptionsSource["OnlyTheseUsers"] = _t('SiteTree.EDITONLYTHESE', "Only these people (choose from list)");
		$editorsOptionsField->setSource($editorsOptionsSource);

		if(!Permission::check('SITETREE_GRANT_ACCESS')) {
			$fields->makeFieldReadonly($viewersOptionsField);
			$fields->makeFieldReadonly($viewerGroupsField);
			$fields->makeFieldReadonly($editorsOptionsField);
			$fields->makeFieldReadonly($editorGroupsField);
		}
		
		$tabContent->setTitle(_t('SiteTree.TABCONTENT', "Content"));
		$tabMain->setTitle(_t('SiteTree.TABMAIN', "Main"));
		$tabMeta->setTitle(_t('SiteTree.TABMETA', "Metadata"));
		$tabBehaviour->setTitle(_t('SiteTree.TABBEHAVIOUR', "Behaviour"));
		$tabReports->setTitle(_t('SiteTree.TABREPORTS', "Reports"));
		$tabAccess->setTitle(_t('SiteTree.TABACCESS', "Access"));
		$tabBacklinks->setTitle(_t('SiteTree.TABBACKLINKS', "BackLinks"));
		
		if(self::$runCMSFieldsExtensions) {
			$this->extend('updateCMSFields', $fields);
		}

		return $fields;
	}
	
	/**
	 *
	 * @param boolean $includerelations a boolean value to indicate if the labels returned include relation fields
	 * 
	 */
	function fieldLabels($includerelations = true) {
		$labels = parent::fieldLabels($includerelations);
		
		$labels['Title'] = _t('SiteTree.PAGETITLE', "Page name");
		$labels['MenuTitle'] = _t('SiteTree.MENUTITLE', "Navigation label");
		$labels['MetaTagsHeader'] = _t('SiteTree.METAHEADER', "Search Engine Meta-tags");
		$labels['MetaTitle'] = _t('SiteTree.METATITLE', "Title");
		$labels['MetaDescription'] = _t('SiteTree.METADESC', "Description");
		$labels['MetaKeywords'] = _t('SiteTree.METAKEYWORDS', "Keywords");
		$labels['ExtraMeta'] = _t('SiteTree.METAEXTRA', "Custom Meta Tags");
		$labels['ClassName'] = _t('SiteTree.PAGETYPE', "Page type", PR_MEDIUM, 'Classname of a page object');
		$labels['ParentType'] = _t('SiteTree.PARENTTYPE', "Page location", PR_MEDIUM);
		$labels['ParentID'] = _t('SiteTree.PARENTID', "Parent page", PR_MEDIUM);
		$labels['ShowInMenus'] =_t('SiteTree.SHOWINMENUS', "Show in menus?");
		$labels['ShowInSearch'] = _t('SiteTree.SHOWINSEARCH', "Show in search?");
		$labels['ProvideComments'] = _t('SiteTree.ALLOWCOMMENTS', "Allow comments on this page?");
		$labels['ViewerGroups'] = _t('SiteTree.VIEWERGROUPS', "Viewer Groups");
		$labels['EditorGroups'] = _t('SiteTree.EDITORGROUPS', "Editor Groups");
		$labels['URLSegment'] = _t('SiteTree.URLSegment', 'URL Segment', PR_MEDIUM, 'URL for this page');
		$labels['Content'] = _t('SiteTree.Content', 'Content', PR_MEDIUM, 'Main HTML Content for a page');
		$labels['HomepageForDomain'] = _t('SiteTree.HomepageForDomain', 'Hompage for this domain');
		$labels['CanViewType'] = _t('SiteTree.Viewers', 'Viewers Groups');
		$labels['CanEditType'] = _t('SiteTree.Editors', 'Editors Groups');
		$labels['ToDo'] = _t('SiteTree.ToDo', 'Todo Notes');
		$labels['Comments'] = _t('SiteTree.Comments', 'Comments');
		$labels['LinkChangeNote'] = _t (
			'SiteTree.LINKCHANGENOTE', 'Changing this page\'s link will also affect the links of all child pages.'
		);
		
		if($includerelations){
			$labels['Parent'] = _t('SiteTree.has_one_Parent', 'Parent Page', PR_MEDIUM, 'The parent page in the site hierarchy');
			$labels['LinkTracking'] = _t('SiteTree.many_many_LinkTracking', 'Link Tracking');
			$labels['ImageTracking'] = _t('SiteTree.many_many_ImageTracking', 'Image Tracking');
			$labels['BackLinkTracking'] = _t('SiteTree.many_many_BackLinkTracking', 'Backlink Tracking');
		}
				
		return $labels;
	}

	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Get the actions available in the CMS for this page - eg Save, Publish.
	 * @return FieldSet The available actions for this page.
	 */
	function getCMSActions() {
		$actions = new FieldSet();

		if($this->isPublished() && $this->canPublish() && !$this->IsDeletedFromStage) {
			// "unpublish"
			$unpublish = FormAction::create('unpublish', _t('SiteTree.BUTTONUNPUBLISH', 'Unpublish'), 'delete');
			$unpublish->describe(_t('SiteTree.BUTTONUNPUBLISHDESC', "Remove this page from the published site"));
			$unpublish->addExtraClass('delete');
			$actions->push($unpublish);
		}

		if($this->stagesDiffer('Stage', 'Live') && !$this->IsDeletedFromStage) {
			if($this->isPublished() && $this->canEdit())	{
				// "rollback"
				$rollback = FormAction::create('rollback', _t('SiteTree.BUTTONCANCELDRAFT', 'Cancel draft changes'), 'delete');
				$rollback->describe(_t('SiteTree.BUTTONCANCELDRAFTDESC', "Delete your draft and revert to the currently published page"));
				$rollback->addExtraClass('delete');
				$actions->push($rollback);
			}
		}

		if($this->canEdit()) {
			if($this->IsDeletedFromStage) {
				if($this->ExistsOnLive) {
					// "restore"
					$actions->push(new FormAction('revert',_t('CMSMain.RESTORE','Restore')));
					// "delete from live"
					$actions->push(new FormAction('deletefromlive',_t('CMSMain.DELETEFP','Delete from the published site')));
				} else {
					// "restore"
					$actions->push(new FormAction('restore',_t('CMSMain.RESTORE','Restore')));
				}
			} else {
				// "delete"
				$actions->push($deleteAction = new FormAction('delete',_t('CMSMain.DELETE','Delete from the draft site')));
				$deleteAction->addExtraClass('delete');
			
				// "save"
				$actions->push(new FormAction('save',_t('CMSMain.SAVE','Save')));
			}
		}

		if($this->canPublish() && !$this->IsDeletedFromStage) {
			// "publish"
			$actions->push(new FormAction('publish', _t('SiteTree.BUTTONSAVEPUBLISH', 'Save and Publish')));
		}
		
		// getCMSActions() can be extended with updateCMSActions() on a decorator
		$this->extend('updateCMSActions', $actions);
		
		return $actions;
	}
	
	/**
	 * Publish this page.
	 * 
	 * @uses SiteTreeDecorator->onBeforePublish()
	 * @uses SiteTreeDecorator->onAfterPublish()
	 */
	function doPublish() {
		$original = Versioned::get_one_by_stage("SiteTree", "Live", "\"SiteTree\".\"ID\" = $this->ID");
		if(!$original) $original = new SiteTree();

		// Handle activities undertaken by decorators
		$this->extend('onBeforePublish', $original);
		
		$this->Status = "Published";
		//$this->PublishedByID = Member::currentUser()->ID;
		$this->write();
		$this->publish("Stage", "Live");

		if(DB::getConn() instanceof MySQLDatabase) {
			// Special syntax for MySQL (grr!)
			// More ANSI-compliant syntax
			DB::query("UPDATE \"SiteTree_Live\", \"SiteTree\"
				SET \"SiteTree_Live\".\"Sort\" = \"SiteTree\".\"Sort\"
				WHERE \"SiteTree_Live\".\"ID\" = \"SiteTree\".\"ID\"
				AND \"SiteTree_Live\".\"ParentID\" = " . sprintf('%d', $this->ParentID) );
			
		} else {
			// More ANSI-compliant syntax
			DB::query("UPDATE \"SiteTree_Live\"
				SET \"Sort\" = \"SiteTree\".\"Sort\"
				FROM \"SiteTree\"
				WHERE \"SiteTree_Live\".\"ID\" = \"SiteTree\".\"ID\"
				AND \"SiteTree_Live\".\"ParentID\" = " . sprintf('%d', $this->ParentID) );
		}

		// Publish any virtual pages that might need publishing
		$linkedPages = DataObject::get("VirtualPage", "\"CopyContentFromID\" = $this->ID");
		if($linkedPages) foreach($linkedPages as $page) {
			$page->copyFrom($page->CopyContentFrom());
			$page->doPublish();
		}

		// Handle activities undertaken by decorators
		$this->extend('onAfterPublish', $original);
	}
	
	/**
	 * Unpublish this page - remove it from the live site
	 * 
	 * @uses SiteTreeDecorator->onBeforeUnpublish()
	 * @uses SiteTreeDecorator->onAfterUnpublish()
	 */
	function doUnpublish() {
		$this->extend('onBeforeUnpublish');
		
		// Call delete on a cloned object so that this one doesn't lose its ID
		$this->flushCache();
		$clone = DataObject::get_by_id("SiteTree", $this->ID);
		$clone->deleteFromStage('Live');

		$this->Status = "Unpublished";
		$this->write();
		
		$this->extend('onAfterUnpublish');
	}
	
	/**
	 * Roll the draft version of this page to match the published page
	 * @param $version Either the string 'Live' or a version number
	 */
	function doRollbackTo($version) {
		$this->publish($version, "Stage", true);
		$this->Status = "Saved (update)";
		$this->writeWithoutVersion();
	}
	
	/**
	 * Revert the draft changes: replace the draft content with the content on live
	 */
	function doRevertToLive() {
		$this->publish("Live", "Stage", false);

		// Use a clone to get the updates made by $this->publish
		$clone = DataObject::get_by_id("SiteTree", $this->ID);
		$clone->Status = "Published";
		$clone->writeWithoutVersion();
		
		$this->extend('onAfterRevertToLive');
	}
	
	/**
	 * Restore the content in the active copy of this SiteTree page to the stage site.
	 * @return The SiteTree object.
	 */
	function doRestoreToStage() {
		// if no record can be found on draft stage (meaning it has been "deleted from draft" before),
		// create an empty record
		if(!DB::query("SELECT \"ID\" FROM \"SiteTree\" WHERE \"ID\" = $this->ID")->value()) {
			$conn = DB::getConn();
			if(method_exists($conn, 'allowPrimaryKeyEditing')) $conn->allowPrimaryKeyEditing('SiteTree', true);
			DB::query("INSERT INTO \"SiteTree\" (\"ID\") VALUES ($this->ID)");
			if(method_exists($conn, 'allowPrimaryKeyEditing')) $conn->allowPrimaryKeyEditing('SiteTree', false);
		}
		
		$oldStage = Versioned::current_stage();
		Versioned::reading_stage('Stage');
		$this->forceChange();
		$this->writeWithoutVersion();
		
		$result = DataObject::get_by_id($this->class, $this->ID);
		
		Versioned::reading_stage($oldStage);
		
		return $result;
	}
	
	function doDeleteFromLive() {
		$origStage = Versioned::current_stage();
		Versioned::reading_stage('Live');
		$this->delete();
		Versioned::reading_stage($origStage);		
	}

	/**
	 * Check if this page is new - that is, if it has yet to have been written
	 * to the database.
	 *
	 * @return boolean True if this page is new.
	 */
	function isNew() {
		/**
		 * This check was a problem for a self-hosted site, and may indicate a
		 * bug in the interpreter on their server, or a bug here
		 * Changing the condition from empty($this->ID) to
		 * !$this->ID && !$this->record['ID'] fixed this.
		 */
		if(empty($this->ID)) return true;

		if(is_numeric($this->ID)) return false;

		return stripos($this->ID, 'new') === 0;
	}


	/**
	 * Check if this page has been published.
	 *
	 * @return boolean True if this page has been published.
	 */
	function isPublished() {
		if($this->isNew())
			return false;

		return (DB::query("SELECT \"ID\" FROM \"SiteTree_Live\" WHERE \"ID\" = $this->ID")->value())
			? true
			: false;
	}

	/**
	 * Get the class dropdown used in the CMS to change the class of a page.
	 * This returns the list of options in the drop as a Map from class name
	 * to text in dropdown.
	 *
	 * @return array
	 */
	protected function getClassDropdown() {
		$classes = self::page_type_classes();
		$currentClass = null;
		$result = array();
		
		$result = array();
		foreach($classes as $class) {
			$instance = singleton($class);
			if((($instance instanceof HiddenClass) || !$instance->canCreate()) && ($class != $this->class)) continue;

			$pageTypeName = $instance->i18n_singular_name();

			if($class == $this->class) {
				$currentClass = $class;
				$result[$class] = $pageTypeName;
			} else {
				$translation = _t(
					'SiteTree.CHANGETO', 
					'Change to "%s"', 
					PR_MEDIUM,
					"Pagetype selection dropdown with class names"
				);

				// @todo legacy fix to avoid empty classname dropdowns when translation doesn't include %s
				if(strpos($translation, '%s') !== FALSE) {
					$result[$class] = sprintf(
						$translation, 
						$pageTypeName
					);
				} else {
					$result[$class] = "{$translation} \"{$pageTypeName}\"";
				}
			}

			// if we're in translation mode, the link between the translated pagetype
			// title and the actual classname might not be obvious, so we add it in parantheses
			// Example: class "RedirectorPage" has the title "Weiterleitung" in German,
			// so it shows up as "Weiterleitung (RedirectorPage)"
			if(i18n::get_locale() != 'en_US') {
				$result[$class] = $result[$class] .  " ({$class})";
			}
		}
		
		// sort alphabetically, and put current on top
		asort($result);
		if($currentClass) {
			$currentPageTypeName = $result[$currentClass];
			unset($result[$currentClass]);
			$result = array_reverse($result);
			$result[$currentClass] = $currentPageTypeName;
			$result = array_reverse($result);
		}
		
		return $result;
	}


	/**
	 * Returns an array of the class names of classes that are allowed
	 * to be children of this class.
	 *
	 * @return array
	 */
	function allowedChildren() {
		$candidates = $this->stat('allowed_children');
		if($candidates && $candidates != "none" && $candidates != "SiteTree_root") {
			foreach($candidates as $candidate) {
				if(substr($candidate,0,1) == '*') {
					$allowedChildren[] = substr($candidate,1);
				} else {
					$subclasses = ClassInfo::subclassesFor($candidate);
					foreach($subclasses as $subclass) {
						if($subclass != "SiteTree_root") $allowedChildren[] = $subclass;
					}
				}
			}
			return $allowedChildren;
		}
	}


	/**
	 * Returns the class name of the default class for children of this page.
	 *
	 * @return string
	 */
	function defaultChild() {
		$default = $this->stat('default_child');
		$allowed = $this->allowedChildren();
		if($allowed) {
			if(!$default || !in_array($default, $allowed))
				$default = reset($allowed);
			return $default;
		}
	}


	/**
	 * Returns the class name of the default class for the parent of this
	 * page.
	 *
	 * @return string
	 */
	function defaultParent() {
		return $this->stat('default_parent');
	}


	/**
	 * Function to clean up the currently loaded page after a reorganise has
	 * been called. It should return a piece of JavaScript to be executed on
	 * the client side, to clean up the results of the reorganise.
	 */
	function cmsCleanup_parentChanged() {
	}


	/**
	 * Get the title for use in menus for this page. If the MenuTitle
	 * field is set it returns that, else it returns the Title field.
	 *
	 * @return string
	 */
	function getMenuTitle(){
		if($value = $this->getField("MenuTitle")) {
			return $value;
		} else {
			return $this->getField("Title");
		}
	}


	/**
	 * Set the menu title for this page.
	 *
	 * @param string $value
	 */
	function setMenuTitle($value) {
		if($value == $this->getField("Title")) {
			$this->setField("MenuTitle", null);
		} else {
			$this->setField("MenuTitle", $value);
		}
	}

	/**
	 * TitleWithStatus will return the title in an <ins>, <del> or
	 * <span class=\"modified\"> tag depending on its publication status.
	 *
	 * @return string
	 */
	function TreeTitle() {
		if($this->IsDeletedFromStage) {
			if($this->ExistsOnLive) {
				$tag ="del title=\"" . _t('SiteTree.REMOVEDFROMDRAFT', 'Removed from draft site') . "\"";
			} else {
				$tag ="del class=\"deletedOnLive\" title=\"" . _t('SiteTree.DELETEDPAGE', 'Deleted page') . "\"";
			}
		} elseif($this->IsAddedToStage) {
			$tag = "ins title=\"" . _t('SiteTree.ADDEDTODRAFT', 'Added to draft site') . "\"";
		} elseif($this->IsModifiedOnStage) {
			$tag = "span title=\"" . _t('SiteTree.MODIFIEDONDRAFT', 'Modified on draft site') . "\" class=\"modified\"";
		} else {
			$tag = '';
		}

		$text = Convert::raw2xml(str_replace(array("\n","\r"),"",$this->MenuTitle));
		return ($tag) ? "<$tag>" . $text . "</" . strtok($tag,' ') . ">" : $text;
	}

	/**
	 * Returns the page in the current page stack of the given level.
	 * Level(1) will return the main menu item that we're currently inside, etc.
	 */
	public function Level($level) {
		$parent = $this;
		$stack = array($parent);
		while($parent = $parent->Parent) {
			array_unshift($stack, $parent);
		}

		return isset($stack[$level-1]) ? $stack[$level-1] : null;
	}
	
	/**
	 * Return the CSS classes to apply to this node in the CMS tree
	 *
	 * @param Controller $controller The controller object that the tree
	 *                               appears on
	 * @return string
	 */
	function CMSTreeClasses($controller) {
		$classes = $this->class;
		if($this->HasBrokenFile || $this->HasBrokenLink)
			$classes .= " BrokenLink";

		if(!$this->canAddChildren())
			$classes .= " nochildren";

		if(
			!$this->canDelete()
			// @todo Temporary workaround for UI-problem: We can't distinguish batch selection for publication from
			// the delete selection (see http://open.silverstripe.com/ticket/3109 and http://open.silverstripe.com/ticket/3302)
			|| !$this->canPublish()
		)
			$classes .= " nodelete";

		if($controller->isCurrentPage($this))
			$classes .= " current";

		if(!$this->canEdit() && !$this->canAddChildren()) 
			$classes .= " disabled";

		if(!$this->ShowInMenus) 
			$classes .= " notinmenu";
			
		if($this->stat('single_instance_only'))
			$classes .= " " . $this->stat('single_instance_only_css_class');
			
		//TODO: Add integration
		/*
		if($this->hasExtension('Translatable') && $controller->Locale != Translatable::default_locale() && !$this->isTranslation())
			$classes .= " untranslated ";
		*/
		$classes .= $this->markingClasses();

		return $classes;
	}
	
	/**
	 * Compares current draft with live version,
	 * and returns TRUE if no draft version of this page exists,
	 * but the page is still published (after triggering "Delete from draft site" in the CMS).
	 * 
	 * @return boolean
	 */
	function getIsDeletedFromStage() {
		if(!$this->ID) return true;
		if($this->isNew()) return false;
		
		$stageVersion = Versioned::get_versionnumber_by_stage('SiteTree', 'Stage', $this->ID);

		// Return true for both completely deleted pages and for pages just deleted from stage.
		return !($stageVersion);
	}
	
	/**
	 * Return true if this page exists on the live site
	 */
	function getExistsOnLive() {
		return (bool)Versioned::get_versionnumber_by_stage('SiteTree', 'Live', $this->ID);
	}

	/**
	 * Compares current draft with live version,
	 * and returns TRUE if these versions differ,
	 * meaning there have been unpublished changes to the draft site.
	 * 
	 * @return boolean
	 */
	public function getIsModifiedOnStage() {
		// new unsaved pages could be never be published
		if($this->isNew()) return false;
		
		$stageVersion = Versioned::get_versionnumber_by_stage('SiteTree', 'Stage', $this->ID);
		$liveVersion =	Versioned::get_versionnumber_by_stage('SiteTree', 'Live', $this->ID);

		return ($stageVersion != $liveVersion);
	}
	
	/**
	 * Compares current draft with live version,
	 * and returns true if no live version exists,
	 * meaning the page was never published.
	 * 
	 * @return boolean
	 */
	public function getIsAddedToStage() {
		// new unsaved pages could be never be published
		if($this->isNew()) return false;
		
		$stageVersion = Versioned::get_versionnumber_by_stage('SiteTree', 'Stage', $this->ID);
		$liveVersion =	Versioned::get_versionnumber_by_stage('SiteTree', 'Live', $this->ID);

		return ($stageVersion && !$liveVersion);
	}
	
	/**
	 * Stops extendCMSFields() being called on getCMSFields().
	 * This is useful when you need access to fields added by subclasses
	 * of SiteTree in a decorator. Call before calling parent::getCMSFields(),
	 * and reenable afterwards.
	 */
	public static function disableCMSFieldsExtensions() {
		self::$runCMSFieldsExtensions = false;
	}
	
	/**
	 * Reenables extendCMSFields() being called on getCMSFields() after
	 * it has been disabled by disableCMSFieldsExtensions().
	 */
	public static function enableCMSFieldsExtensions() {
		self::$runCMSFieldsExtensions = true;
	}

	function providePermissions() {
		return array(
			'SITETREE_GRANT_ACCESS' => _t(
				'SiteTree.PERMISSION_GRANTACCESS_DESCRIPTION',
				'Control which groups can access or edit certain pages'
			),
			'SITETREE_VIEW_ALL' => _t(
				'SiteTree.VIEW_ALL_DESCRIPTION',
				'Can view any page on the site, bypassing page specific security'
			),
			'SITETREE_EDIT_ALL' => _t(
				'SiteTree.EDIT_ALL_DESCRIPTION',
				'Can edit any page on the site, bypassing page specific security'
			),
			'SITETREE_REORGANISE' => _t(
				'SiteTree.REORGANISE_DESCRIPTION',
				'Can reorganise the site tree'
			),
		);
	}
	
	function i18n_singular_name() {
		$addAction = $this->stat('add_action');
		$name = (!empty($addAction)) ? $addAction : $this->singular_name();
		return _t($this->class.'.SINGULARNAME', $name);
	}
	
	/**
	 * Overloaded to also provide entities for 'Page' class which is usually
	 * located in custom code, hence textcollector picks it up for the wrong folder.
	 */
	function provideI18nEntities() {
		$entities = parent::provideI18nEntities();
		
		if(isset($entities['Page.SINGULARNAME'])) $entities['Page.SINGULARNAME'][3] = 'sapphire';
		if(isset($entities['Page.PLURALNAME'])) $entities['Page.PLURALNAME'][3] = 'sapphire';		

		return $entities;
	}
	
	function getParentType() {
		return $this->ParentID == 0 ? 'root' : 'subpage';
	}
	
	static function reset() {
		self::$cache_permissions = array();
	}

}

?>
