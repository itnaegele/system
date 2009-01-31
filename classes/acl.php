<?php
/**
 * @package Habari
 *
 */

/**
 * Access Control List class
 *
 * The default Habari ACL class implements groups, and group permissions
 * Users are assigned to one or more groups.
 * Groups are assigned one or more permissions.
 * Membership in any group that grants a permission
 * means you have that permission.  Membership in any group that denies
 * that permission denies the user that permission, even if another group
 * grants that permission.
 * @todo Rename all functions and variables to normalize conventions: Users and groups have "access" to a "token".  The access applied to a token is a "permission".  A "token" alone is not a "permission".
 *
 **/
class ACL {
	/**
	 * How to handle a permission request for a permission that is not in the permission list.
	 * For example, if you request $user->can('some non-existent permission') then this value is returned.
	 **/
	const ACCESS_NONEXISTENT_PERMISSION = 0;

	public static $access_names = array( 'read', 'edit', 'delete', 'create' );

	/**
	 * Check a permission bitmask for a particular access type.
	 * @param Bitmask $bitmask The permission bitmask
	 * @param mixed $access The name of the access to check against (read, write, full)
	 * @return bool Returns true if the given access meets exceeds the access to check against
	 */
	public static function access_check( $bitmask, $access )
	{
		switch($access) {
			case 'full':
				return $bitmask->value == $bitmask->full;
			case 'any':
				return $bitmask->value != 0;
			case 'deny':
				return $bitmask->value == 0;
			default:
				return $bitmask->$access;
		}
	}

	/**
	 * Get a Bitmask object representing the supplied access integer
	 *
	 * @param integer $mask The access mask, usually stored in the database
	 * @return Bitmask An object representing the access value
	 */
	public static function get_bitmask( $mask )
	{
		$bitmask = new Bitmask( self::$access_names, $mask );
		return $bitmask;
	}

	/**
	 * Check the permission bitmask to find the access type
	 * <em>This function is horribly, horribly broken, and shouldn't be used.
	 * For example, it will return that a permission is only "read" when it is actually "read+write".</em>
	 * Use get_bitmask() to retrieve a Btimask instead, and use its properties for testing values.
	 * @param mixed $mask The access bitmask
	 * @return mixed The permission level granted, or false for none
	 */
	public static function access_level( $mask )
	{
		$bitmask = new Bitmask( self::$access_names, $mask );


		if ( $bitmask->value == $bitmask->full ) {
			return 'full';
		} else {
			foreach ( $bitmask->flags as $flag ) {
				if ( $bitmask->$flag ) {
					return $flag;
				}
			}
		}
		return false;

	}

	/**
	 * Create a new permission token, and save it to the permission tokens table
	 * @param string $name The name of the permission
	 * @param string $description The description of the permission
	 * @return mixed the ID of the newly created permission, or boolean FALSE
	**/
	public static function create_token( $name, $description )
	{
		$name = self::normalize_token( $name );
		// first, make sure this isn't a duplicate
		if ( ACL::token_exists( $name ) ) {
			return false;
		}
		$allow = true;
		// Plugins have the opportunity to prevent adding this token
		$allow = Plugins::filter('token_create_allow', $allow, $name, $description );
		if ( ! $allow ) {
			return false;
		}
		Plugins::act('token_create_before', $name, $description);
		$result = DB::query('INSERT INTO {tokens} (name, description) VALUES (?, ?)', array( $name, $description) );

		if ( ! $result ) {
			// if it didn't work, don't bother trying to log it
			return false;
		}

		// Add the token to the admin group
		$token = ACL::token_id( $name );
		$admin = UserGroup::get( 'admin');
		if ( $admin ) {
			ACL::grant_group( $admin->id, $token, 'full' );
		}

		EventLog::log('New permission created: ' . $name, 'info', 'default', 'habari');
		Plugins::act('permission_create_after', $name, $description );
		return $result;
	}

	/**
	 * Remove a permission token, and any assignments of it
	 * @param mixed $permission a permission ID or name
	 * @return bool whether the permission was deleted or not
	**/
	public static function destroy_token( $token )
	{
		// make sure the permission exists, first
		if ( ! self::token_exists( $token ) ) {
			return false;
		}

		// grab token ID
		$token_id = self::token_id( $token );

		$allow = true;
		// plugins have the opportunity to prevent deletion
		$allow = Plugins::filter('token_destroy_allow', $allow, $token_id);
		if ( ! $allow ) {
			return false;
		}
		Plugins::act('token_destroy_before', $token_id );
		// capture the token name
		$name = DB::get_value( 'SELECT name FROM {tokens} WHERE id=?', array( $token_id ) );
		// remove all references to this permissions
		$result = DB::query( 'DELETE FROM {group_token_permissions} WHERE token_id=?', array( $token_id ) );
		$result = DB::query( 'DELETE FROM {user_token_permissions} WHERE token_id=?', array( $token_id ) );
		// remove this token
		$result = DB::query( 'DELETE FROM {tokens} WHERE id=?', array( $token_id ) );
		if ( ! $result ) {
			// if it didn't work, don't bother trying to log it
			return false;
		}
		EventLog::log( sprintf(_t('Permission token deleted: %s'), $name), 'info', 'default', 'habari');
		Plugins::act('token_destroy_after', $token_id );
		return $result;
	}

	/**
	 * Get an array of QueryRecord objects containing all permission tokens
	 * @param string $order the order in which to sort the returning array
	 * @return array an array of QueryRecord objects containing all tokens
	**/
	public static function all_tokens( $order = 'id' )
	{
		$order = strtolower( $order );
		if ( ( 'id' != $order ) && ( 'name' != $order ) && ( 'description' != $order ) ) {
			$order = 'id';
		}
		$tokens = DB::get_results( 'SELECT id, name, description FROM {tokens} ORDER BY ' . $order );
		return $tokens ? $tokens : array();
	}

	/**
	 * Get a permission token's name by its ID
	 * @param int $id a permission ID
	 * @return string the name of the permission, or boolean FALSE
	**/
	public static function token_name( $id )
	{
		if ( ! is_int( $id ) ) {
			return false;
		} else {
			return DB::get_value( 'SELECT name FROM {tokens} WHERE id=?', array( $id ) );
		}
	}

	/**
	 * Get a permission token's ID by its name
	 * @param string $name the name of the permission
	 * @return int the permission's ID
	**/
	public static function token_id( $name )
	{
		if( is_numeric($name) ) {
			return intval( $name );
		}
		$name = self::normalize_token( $name );
		return intval( DB::get_value( 'SELECT id FROM {tokens} WHERE name=?', array( $name ) ) );
	}

	/**
	 * Fetch a permission token's description from the DB
	 * @param mixed $permission a permission name or ID
	 * @return string the description of the permission
	**/
	public static function token_description( $permission )
	{
		if ( is_int( $permission) ) {
			$query = 'id';
		} else {
			$query = 'name';
			$permission = self::normalize_token( $permission );
		}
		return DB::get_value( "SELECT description FROM {tokens} WHERE $query=?", array( $permission ) );
	}

	/**
	 * Determine whether a permission token exists
	 * @param mixed $permission a permission name or ID
	 * @return bool whether the permission exists or not
	**/
	public static function token_exists( $permission )
	{
		if ( is_numeric( $permission ) ) {
			$query = 'id';
		}
		else {
			$query = 'name';
			$permission = self::normalize_token( $permission );
		}
		return ( (int) DB::get_value( "SELECT COUNT(id) FROM {tokens} WHERE $query=?", array( $permission ) ) > 0 );
	}

	/**
	 * Determine whether a group can perform a specific action
	 * @param mixed $group A group ID or name
	 * @param mixed $token_id A permission token ID or name
	 * @param string $access Check for 'create', 'read', 'update', 'delete', or 'full' access
	 * @return bool Whether the group can perform the action
	**/
	public static function group_can( $group, $token_id, $access = 'full' )
	{
		$bitmask = get_group_token_access( $group, $token_id );

		if ( isset( $bitmask ) && self::access_check( $bitmask, $access ) ) {
			// the permission has been granted to this group
			return true;
		}
		// either the permission hasn't been granted, or it's been
		// explicitly denied.
		return false;
	}

	/**
	 * Determine whether a user can perform a specific action
	 * @param mixed $user A user object, user ID or a username
	 * @param mixed $token_id A permission ID or name
	 * @param string $access Check for 'create', 'read', 'update', 'delete', or 'full' access
	 * @return bool Whether the user can perform the action
	**/
	public static function user_can( $user, $token_id, $access = 'full' )
	{

		$result = self::get_user_token_access( $user, $token_id );

		if ( isset( $result ) && self::access_check( $result, $access ) ) {
			return true;
		}

		$super_user_access = self::get_user_token_access( $user, 'super_user' );
		if ( isset( $super_user_access ) && self::access_check( $super_user_access, 'any' ) ) {
			return true;
		}

		// either the permission hasn't been granted, or it's been
		// explicitly denied.
		return false;
	}

	/**
	 * Determine whether a user is denied permission to perform a specific action
	 * @param mixed $user A User object, user ID or a username
	 * @param mixed $token_id A permission ID or name
	 * @return bool Whether the user can perform the action
	 **/
	public static function user_cannot( $user, $token_id )
	{

		$result = self::get_user_token_access( $user, $token_id );

		if ( isset( $result ) && self::access_check( $result, 'deny' ) ) {
			return true;
		}

		// either the permission hasn't been granted, or it's been
		// explicitly denied.
		return false;
	}


	/**
	 * Return the access bitmask to a specific token for a specific user
	 *
	 * @param mixed $user A User object instance or user id
	 * @param mixed $token_id A permission token name or token ID
	 * @return integer An access bitmask
	 * @todo Implement cache on these permissions
	 */
	public static function get_user_token_access( $user, $token_id )
	{
		// Use only numeric ids internally
		$token_id = self::token_id( $token_id );

		/**
		 * Do we allow perms that don't exist?
		 * When ACL is functional ACCESS_NONEXISTENT_PERMISSION should be false by default.
		 */
		if ( is_null( $token_id ) ) {
			return self::get_bitmask( self::ACCESS_NONEXISTENT_PERMISSION );
		}

		// if we were given a user ID, use that to fetch the group membership from the DB
		if ( is_numeric( $user ) ) {
			$user_id = $user;
		} else {
			// otherwise, make sure we have a User object, and get
			// the groups from that
			if ( ! $user instanceof User ) {
				$user = User::get( $user );
			}
			$user_id = $user->id;
		}

		// Implement cache RIGHT HERE

		/**
		 * Jay Pipe's explanation of the following SQL
		 * 1) Look into user_permissions for the user and the token.
		 * If exists, use that permission flag for the check. If not,
		 * go to 2)
		 *
		 * 2) Look into the group_permissions joined to
		 * users_groups for the user and the token.  Order the results
		 * by the permission_id flag. The lower the flag value, the
		 * fewest permissions that group has. Use the first record's
		 * permission flag to check the ACL.
		 *
		 * This gives the system very fine grained control and grabbing
		 * the permission flag and can be accomplished in a single SQL
		 * call.
		 */
		$sql = <<<SQL
SELECT permission_id
  FROM {user_token_permissions}
  WHERE user_id = :user_id
  AND token_id = :token_id
UNION ALL
SELECT gp.permission_id
  FROM {users_groups} ug
  INNER JOIN {group_token_permissions} gp
  ON ug.group_id = gp.group_id
  AND ug.user_id = :user_id
  AND gp.token_id = :token_id
  ORDER BY permission_id ASC
SQL;
		$accesses = DB::get_column( $sql, array( ':user_id' => $user_id, ':token_id' => $token_id ) );

		$result = 0;
		foreach ( $accesses as $access ) {
			if ( $access == 0 ) {
				$result = 0;
				break;
			}
			else {
				$result |= $access;
			}
		}

		return self::get_bitmask( $result );
	}

	/**
	 * Get all the tokens for a given user with a particular kind of access
	 * @param mixed $user A user object, user ID or a username
	 * @param string $access Check for 'create' or 'read', 'update', or 'delete' access
	 * @return array of token IDs
	**/
	public static function user_tokens( $user, $access = 'full', $posts_only = false )
	{
		$bitmask = new Bitmask ( self::$access_names, $access );
		$tokens = array();

		$super_user_access = self::get_user_token_access( $user, 'super_user' );
		if ( isset( $super_user_access ) && self::access_check( $super_user_access, 'any' ) ) {
			$result = DB::get_results('SELECT id, ? as permission_id FROM {tokens}', array($bitmask->full) );
		}
		else {
			// convert $user to an ID
			if ( is_numeric( $user ) ) {
				$user_id = $user;
			}
			else {
				if ( ! $user instanceof User ) {
					$user = User::get( $user );
				}
				$user_id = $user->id;
			}

			$sql = <<<SQL
SELECT token_id, permission_id
	FROM {user_token_permissions}
	WHERE user_id = :user_id
UNION ALL
SELECT gp.token_id, gp.permission_id
  FROM {users_groups} ug
  INNER JOIN {group_token_permissions} gp
  ON ug.group_id = gp.group_id
  AND ug.user_id = :user_id
  ORDER BY token_id ASC
SQL;
			$result = DB::get_results( $sql, array( ':user_id' => $user_id ) );
		}

		if ( $posts_only ) {
			$post_tokens = DB::get_column('SELECT token_id FROM {post_tokens} GROUP BY token_id');
		}

		foreach ( $result as $token ) {
			$bitmask->value = $token->permission_id;
			if ( $access == 'deny' && $bitmask->value == 0 ) {
				$tokens[] = $token->token_id;
			}
			else {
				if ( $bitmask->$access && ( !$posts_only || in_array($token->token_id, $post_tokens) ) ) {
					$tokens[] = $token->token_id;
				}
			}
		}
		return $tokens;
	}

	/**
	 * Get the access bitmask of a group for a specific permission token
	 * @param integer $group The group ID
	 * @param mixed $token_id A permission name or ID
	 * @return an access bitmask
	 **/
	public static function get_group_token_access( $group, $token_id )
	{
		// Use only numeric ids internally
		$group = UserGroup::id( $group );
		$token_id = self::token_id( $token_id );
		$sql = 'SELECT permission_id FROM {group_token_permissions} WHERE
			group_id=? AND token_id=?;';

		$result = DB::get_value( $sql, array( $group, $token_id) );

		if ( isset( $result ) ) {
			return self::get_bitmask($result);
		}
		return null;
	}

	/**
	 * Grant a permission to a group
	 * @param integer $group_id The group ID
	 * @param mixed $token_id The name or ID of the permission token to grant
	 * @param string $access The kind of access to assign the group
	 * @return Result of the DB query
	 **/
	public static function grant_group( $group_id, $token_id, $access = 'full' )
	{
		$token_id = self::token_id( $token_id );
		$access_mask = DB::get_value( 'SELECT permission_id FROM {group_token_permissions} WHERE group_id=? AND token_id=?',
			array( $group_id, $token_id ) );
		if ( $access_mask ===  false ) {
			$access_mask = 0; // default is 'deny' (bitmask 0)
		}

		$bitmask = self::get_bitmask( $access_mask );

		if ( $access == 'full' ) {
			$bitmask->value= $bitmask->full;
		}
		elseif ( $access == 'deny' ) {
			$bitmask->value = 0;
		}
		else {
			$bitmask->$access = true;
		}

		// DB::update will insert if the token is not already in the group tokens table
		$result = DB::update(
			'{group_token_permissions}',
			array( 'permission_id' => $bitmask->value ),
			array( 'group_id' => $group_id, 'token_id' => $token_id )
		);

		$ug = UserGroup::get_by_id( $group_id );
		$ug->clear_permissions_cache();

		return $result;
	}

	/**
	 * Grant a permission to a user
	 * @param integer $user_id The user ID
	 * @param integer $token_id The name or ID of the permission token to grant
	 * @param string $access The kind of access to assign the group
	 * @return Result of the DB query
	 **/
	public static function grant_user( $user_id, $token_id, $access = 'full' )
	{
		$token_id = self::token_id( $token_id );
		$access_mask = DB::get_value( 'SELECT permission_id FROM {user_token_permissions} WHERE user_id=? AND token_id=?',
			array( $user_id, $token_id ) );
		if ( $access_mask ===  false ) {
			$permission_bit = 0; // default is 'deny' (bitmask 0)
		}

		$bitmask = self::get_bitmask( $access_mask );

		if ( $access == 'full' ) {
			$bitmask->value= $bitmask->full;
		}
		elseif ( $access == 'deny' ) {
			$bitmask->value = 0;
		}
		else {
			$bitmask->$access = true;
		}

		$result = DB::update(
			'{user_token_permissions}',
			array( 'permission_id' => $bitmask->value ),
			array( 'user_id' => $user_id, 'token_id' => $token_id )
		);

		return $result;
	}

	/**
	 * Deny permission to a group
	 * @param integer $group_id The group ID
	 * @param mixed $token_id The name or ID of the permission token
	 * @return Result of the DB query
	 **/
	public static function deny_group( $group_id, $token_id )
	{
		self::grant_group( $group_id, $token_id, 'deny' );
	}

	/**
	 * Deny permission to a user
	 * @param integer $user_id The user ID
	 * @param mixed $token_id The name or ID of the permission token
	 * @return Result of the DB query
	 **/
	public static function deny_user( $user_id, $token_id )
	{
		self::grant_user( $group_id, $token_id, 'deny' );
	}

	/**
	 * Remove a permission token from the group permissions table
	 * @param integer $group_id The group ID
	 * @param mixed $token_id The name or ID of the permission token
	 * @return the result of the DB query
	 **/
	public static function revoke_group_token( $group_id, $token_id )
	{
		$token_id = self::token_id( $token_id );
		$result = DB::delete( '{group_token_permissions}',
			array( 'group_id' => $group_id, 'token_id' => $token_id ) );

		$ug = UserGroup::get_by_id( $group_id );
		$ug->clear_permissions_cache();

		return $result;
	}

	/**
	 * Remove a permission token from the user permissions table
	 * @param integer $user_id The user ID
	 * @param mixed $token_id The name or ID of the permission token
	 * @return the result of the DB query
	 **/
	public static function revoke_user_token( $user_id, $token_id )
	{
		$token_id = self::token_id( $token_id );
		$result = DB::delete( '{user_token_permissions}',
			array( 'user_id' => $user_id, 'token_id' => $token_id ) );

		return $result;
	}

	/**
	 * Convert a token name into a valid format
	 *
	 * @param string $name The name of a permission
	 * @return string The permission with spaces converted to underscores and all lowercase
	 */
	public static function normalize_token( $name )
	{
		return strtolower( preg_replace( '/\s+/', '_', trim($name) ) );
	}

	/**
	 * Creates the default set of permissions.
	 */
	public static function create_default_permissions()
	{
		self::create_token( 'super_user', 'Permissions for super users' );
		self::create_token( 'own_posts', 'Permissions on one\'s own posts' );
		self::create_token( 'manage_all_comments', 'Manage comments on all posts' );
		self::create_token( 'manage_own_post_comments', 'Manage comments on one\'s own posts' );
		self::create_token( 'manage_tags', 'Manage tags' );
		self::create_token( 'manage_options', 'Manage options' );
		self::create_token( 'manage_theme', 'Change theme' );
		self::create_token( 'manage_theme_config', 'Configure the active theme' );
		self::create_token( 'manage_plugins', 'Activate/deactivate plugins' );
		self::create_token( 'manage_plugins_config', 'Configure active plugins' );
		self::create_token( 'manage_import', 'Use the importer' );
		self::create_token( 'manage_users', 'Add, remove, and edit users' );
		self::create_token( 'manage_groups', 'Manage groups and permissions' );
		self::create_token( 'manage_logs', 'Manage logs' );
	}
}
?>
