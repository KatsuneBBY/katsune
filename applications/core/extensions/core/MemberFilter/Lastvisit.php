<?php
/**
 * @brief		Member filter extension: member last visit date
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		20 June 2013
 */

namespace IPS\core\extensions\core\MemberFilter;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * @brief	Member filter: Member last visit date
 */
class _Lastvisit
{
	/**
	 * Determine if the filter is available in a given area
	 *
	 * @param	string	$area	Area to check
	 * @return	bool
	 */
	public function availableIn( $area )
	{
		return in_array( $area, array( 'bulkmail', 'group_promotions', 'automatic_moderation' ) );
	}

	/** 
	 * Get Setting Field
	 *
	 * @param	mixed	$criteria	Value returned from the save() method
	 * @return	array 	Array of form elements
	 */
	public function getSettingField( $criteria )
	{
		return array(
			new \IPS\Helpers\Form\Custom( 'bmf_members_last_visit', array( 0 => isset( $criteria['range'] ) ? $criteria['range'] : '', 1 => isset( $criteria['days'] ) ? $criteria['days'] : NULL, 3 => isset( $criteria['days_lt'] ) ? $criteria['days_lt'] : NULL ), FALSE, array(
				'getHtml'	=> function( $element )
				{
					$dateRange = new \IPS\Helpers\Form\DateRange( "{$element->name}[0]", $element->value[0], FALSE );

					return \IPS\Theme::i()->getTemplate( 'members', 'core', 'global' )->dateFilters( $dateRange, $element );
				}
			) )
		);
	}
	
	/**
	 * Save the filter data
	 *
	 * @param	array	$post	Form values
	 * @return	mixed			False, or an array of data to use later when filtering the members
	 * @throws \LogicException
	 */
	public function save( $post )
	{
		if ( isset( $post['bmf_members_last_visit'][2] ) )
		{
			if ( $post['bmf_members_last_visit'][2] == 'days' )
			{
				return $post['bmf_members_last_visit'][1] ? array( 'days' => intval( $post['bmf_members_last_visit'][1] ) ) : FALSE;
			}
			else if ( $post['bmf_members_last_visit'][2] == 'days_lt' )
			{
				return $post['bmf_members_last_visit'][3] ? array( 'days_lt' => intval( $post['bmf_members_last_visit'][3] ) ) : FALSE;
			}
			elseif( $post['bmf_members_last_visit'][2] == 'range' )
			{
				return ( empty( $post['bmf_members_last_visit'][0] ) OR empty( $post['bmf_members_last_visit'][0]['start'] ) ) ? FALSE : array( 'range' => json_decode( json_encode( $post['bmf_members_last_visit'][0] ), TRUE ) );
			}
		}
		else
		{
			/* Normalize objects to their array form. Bulk mailer stores options as a json array where as member export does not, so $data['range']['start'] is a DateTime object */
			return ( empty( $post['bmf_members_last_visit'][0] ) OR empty( $post['bmf_members_last_visit'][0]['start'] ) ) ? FALSE : array( 'range' => json_decode( json_encode( $post['bmf_members_last_visit'][0] ), TRUE ) );
		}
	}
	
	/**
	 * Get where clause to add to the member retrieval database query
	 *
	 * @param	mixed				$data	The array returned from the save() method
	 * @return	array|NULL			Where clause - must be a single array( "clause", ...binds )
	 */
	public function getQueryWhereClause( $data )
	{
		if( !empty($data['range']) AND !empty($data['range']['end']) )
		{
			$start	= NULL;
			$end	= NULL;
			if ( $data['range']['start'] )
			{
				try
				{
					/* Try just what is stored */
					$start = new \IPS\DateTime( $data['range']['start'] );
				}
				catch( \Exception $e )
				{
					/* If there was an error, try dashes so DateTime will assume European dates. @see <a href='http://php.net/manual/en/function.strtotime.php#refsect1-function.strtotime-notes'>PHP Documentation</a> */
					$start = new \IPS\DateTime( str_replace( '/', '-', $data['range']['start'] ) );
				}
			}
			
			if ( $data['range']['end'] )
			{
				try
				{
					$end = new \IPS\DateTime( $data['range']['end'] );
				}
				catch( \Exception $e )
				{
					$end = new \IPS\DateTime( str_replace( '/', '-', $data['range']['end'] ) );
				}
			}

			if( $start and $end )
			{
				return array( "core_members.last_activity BETWEEN {$start->getTimestamp()} AND {$end->getTimestamp()}" );
			}
		}
		elseif( !empty( $data['days'] ) AND (int) $data['days'] )
		{
			$date = \IPS\DateTime::create()->sub( new \DateInterval( 'P' . (int) $data['days'] . 'D' ) );

			return array( "core_members.last_activity < {$date->getTimestamp()}" );
		}
		elseif( !empty( $data['days_lt'] ) AND (int) $data['days_lt'] )
		{
			$date = \IPS\DateTime::create()->sub( new \DateInterval( 'P' . (int) $data['days_lt'] . 'D' ) );

			return array( "core_members.last_activity > {$date->getTimestamp()}" );
		}

		return NULL;
	}

	/**
	 * Determine if a member matches specified filters
	 *
	 * @note	This is only necessary if availableIn() includes group_promotions
	 * @param	\IPS\Member	$member		Member object to check
	 * @param	array 		$filters	Previously defined filters
	 * @param	object|NULL	$object		Calling class
	 * @return	bool
	 */
	public function matches( \IPS\Member $member, $filters, $object=NULL )
	{
		/* If we aren't filtering by this, then any member matches */
		if( ( !isset( $filters['range'] ) OR !$filters['range'] OR empty( $filters['range']['end'] ) ) AND ( !isset( $filters['days'] ) OR !$filters['days'] ) )
		{
			return TRUE;
		}

		$lastVisit = \IPS\DateTime::ts( $member->last_visit );

		if( !empty( $filters['range'] ) AND !empty( $filters['range']['end'] ) )
		{
			$start	= NULL;
			$end	= NULL;
			
			if ( $filters['range']['start'] )
			{
				try
				{
					$start = new \IPS\DateTime( $filters['range']['start'] );
				}
				catch( \Exception $e )
				{
					$start = new \IPS\DateTime( $filters['range']['start'] );
				}
			}
			
			if ( $filters['range']['end'] )
			{
				try
				{
					$end = new \IPS\DateTime( $filters['range']['end'] );
				}
				catch( \Exception $e )
				{
					$end = new \IPS\DateTime( str_replace( '/', '-', $filters['range']['end'] ) );
				}
			}

			if( $start and $end )
			{
				return (bool) ( $lastVisit->getTimestamp() > $start->getTimestamp() AND $lastVisit->getTimestamp() < $end->getTimestamp() );
			}
		}
		elseif( !empty( $filters['days'] ) AND (int) $filters['days'] )
		{
			return (bool) ( $lastVisit->add( new \DateInterval( 'P' . (int) $filters['days'] . 'D' ) )->getTimestamp() < time() );
		}
	}
	
	/**
	 * Return a lovely human description for this rule if used
	 *
	 * @param	mixed				$filters	The array returned from the save() method
	 * @return	string|NULL
	 */
	public function getDescription( $filters )
	{
		if( !empty( $filters['range'] ) AND !empty( $filters['range']['end'] ) )
		{
			$start	= NULL;
			$end	= NULL;
			
			if ( $filters['range']['start'] )
			{
				try
				{
					$start = new \IPS\DateTime( $filters['range']['start'] );
				}
				catch( \Exception $e )
				{
					$start = new \IPS\DateTime( $filters['range']['start'] );
				}
			}
			
			if ( $filters['range']['end'] )
			{
				try
				{
					$end = new \IPS\DateTime( $filters['range']['end'] );
				}
				catch( \Exception $e )
				{
					$end = new \IPS\DateTime( str_replace( '/', '-', $filters['range']['end'] ) );
				}
			}

			if ( $start and $end )
			{
				return \IPS\Member::loggedIn()->language()->addToStack( 'member_filter_core_lastvisit_range_desc', NULL, array( 'sprintf' => array( $start->localeDate(), $end->localeDate() ) ) );
			}
		}
		elseif( !empty( $filters['days'] ) AND (int) $filters['days'] )
		{
			return \IPS\Member::loggedIn()->language()->addToStack( 'member_filter_core_lastvisit_days_desc', NULL, array( 'sprintf' => array( $filters['days'] ) ) );
		}
		elseif( !empty( $filters['days_lt'] ) AND (int) $filters['days_lt'] )
		{
			return \IPS\Member::loggedIn()->language()->addToStack( 'member_filter_core_lastvisit_days_lt_desc', NULL, array( 'sprintf' => array( $filters['days_lt'] ) ) );
		}
		
		return NULL;
	}
}