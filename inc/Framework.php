<?php
/**
 * @package WPOD
 * @version 1.0.0
 * @author Felix Arntz <felix-arntz@leaves-and-love.net>
 */

namespace WPOD;

class Framework
{
  private static $instance = null;

  public static function instance()
  {
    if( self::$instance == null )
    {
      self::$instance = new self;
    }
    return self::$instance;
  }

  private $initialized = false;

  private $groups = array();
  private $sets = array();
  private $members = array();
  private $sections = array();
  private $fields = array();

  private function __construct()
  {
    add_action( 'after_setup_theme', array( $this, 'init' ), 1 );
  }

  /*
   * ===================================================================================================
   * BASIC USAGE
   * You can either use the filter 'wpod' to create a multidimensional array of nested components 
   * (groups, sets, members, sections and fields).
   * Alternatively, you can use the action 'wpod_oo' which passes this class to the hooked function.
   * In that function, you should then use the function 'add' (which you see right below) to directly
   * add components (groups, sets, members, sections and fields).
   *
   * Both methods can be used interchangeably and are compatible with each other since the plugin
   * internally runs through the filtered array and then also uses the 'add' method on each component
   * in there. The action is executed after that process.
   * ===================================================================================================
   */

  public function add( $slug, $type, $args, $parent = '' )
  {
    $type = strtolower( $type );
    if( $this->is_valid_type( $type ) )
    {
      $arrayname = $type . 's';
      $classname = 'Components\\' . ucfirst( $type );
      if( $type == 'group' || !empty( $parent ) )
      {
        array_push( $this->$arrayname, new $classname( $slug, $args, $parent ) );
      }
      else
      {
        wpod_doing_it_wrong( __METHOD__, sprintf( __( 'The %1$s %2$s was not provided a parent.', 'wpod' ), $type, $slug ) );
      }
    }
    else
    {
      wpod_doing_it_wrong( __METHOD__, sprintf( __( 'The type %s is not a valid type for a component.', 'wpod' ), $type ) );
    }
  }

  /*
   * ===================================================================================================
   * INTERNAL FUNCTIONS
   * The following functions should never be used outside the actual Options, Definitely plugin.
   * ===================================================================================================
   */

  public function init()
  {
    if( !$this->initialized )
    {
      $raw = array();
      foreach( $this->get_default_groups() as $group_slug )
      {
        $raw[ $group_slug ] = array(
          'sets'              => array(),
        );
      }

      // filter for the components array
      $raw = apply_filters( 'wpod', $raw );

      foreach( $raw as $group_slug => $group )
      {
        $this->add( $group_slug, 'group', $group );
        foreach( $group['sets'] as $set_slug => $set )
        {
          $this->add( $set_slug, 'set', $set, $group_slug );
          foreach( $set['members'] as $member_slug => $member )
          {
            $this->add( $member_slug, 'member', $member, $set_slug );
            foreach( $member['sections'] as $section_slug => $section )
            {
              $this->add( $section_slug, 'section', $section, $member_slug );
              foreach( $section['fields'] as $field_slug => $field )
              {
                $this->add( $field_slug, 'field', $field, $section_slug );
              }
            }
          }
        }
      }

      // action for the object-oriented alternative for more experienced users
      do_action( 'wpod_oo', $this );

      $this->initialized = true;
    }
  }

  public function query( $args = array(), $single = false )
  {
    $args = wp_parse_args( $args, array(
      'slug'        => array(),
      'type'        => 'field',
      'parent_slug' => array(),
      'parent_type' => 'section',
    ) );
    extract( $args );

    $results = false;
    if( $this->is_valid_type( $type ) )
    {
      $arrayname = $type . 's';
      if( !is_array( $slug ) )
      {
        if( !empty( $slug ) )
        {
          $slug = array( $slug );
        }
        else
        {
          $slug = array();
        }
      }
      if( !is_array( $parent_slug ) )
      {
        if( !empty( $parent_slug ) )
        {
          $parent_slug = array( $parent_slug );
        }
        else
        {
          $parent_slug = array();
        }
      }

      $results = $this->$arrayname;
      if( count( $slug ) > 0 )
      {
        $results = $this->query_by_slug( $slug, $results, $type );
      }
      if( $type != 'group' && $this->is_valid_type( $parent_type ) && count( $parent_slug ) > 0 && count( $results ) > 0 )
      {
        $results = $this->query_by_parent( $parent_slug, $parent_type, $results, $type );
      }

      if( $single )
      {
        if( count( $results ) > 0 )
        {
          $results = $results[0];
        }
        else
        {
          $results = false;
        }
      }
    }
    else
    {
      wpod_doing_it_wrong( __METHOD__, sprintf( __( 'The type %s is not a valid type for a component.', 'wpod' ), $type ) );
    }
    return $results;
  }

  private function query_by_slug( $slug, $haystack, $haystack_type )
  {
    $results = array();
    foreach( $haystack as $component )
    {
      if( in_array( $component->slug, $slug )
      {
        $results[] = $component;
      }
    }
    return $results;
  }

  private function query_by_parent( $parent_slug, $parent_type, $haystack, $haystack_type )
  {
    while( ( $current_type = $this->get_next_inferior_type( $parent_type ) ) != $haystack_type )
    {
      $current_arrayname = $current_type .'s';
      $current_haystack = $this->query_by_parent( $parent_slug, $parent_type, $this->$current_arrayname, $current_type );
      $parent_slug = array_map( array( $this, 'component_to_slug_callback' ), $current_haystack );
      $parent_type = $current_type;
    }
    foreach( $haystack as &$component )
    {
      if( !in_array( $component->parent, $parent_slug ) )
      {
        unset( $component );
      }
    }
    return $haystack;
  }

  private function is_valid_type( $type )
  {
    return in_array( $type, $this->get_type_whitelist() );
  }

  private function get_type_whitelist()
  {
    return array( 'group', 'set', 'member', 'section', 'field' );
  }

  private function get_next_superior_type( $type )
  {
    $types = $this->get_type_whitelist();
    $type_key = array_search( $type, $types );
    if( $type_key )
    {
      return $types[ $type_key - 1 ];
    }
    return false;
  }

  private function get_next_inferior_type( $type )
  {
    $types = $this->get_type_whitelist();
    $type_key = array_search( $type, $types );
    if( $type_key !== false && $type_key < 4 )
    {
      return $types[ $type_key + 1 ];
    }
    return false;
  }

  private function get_default_groups()
  {
    return array(
      'dashboard',
      'posts',
      'media',
      'links',
      'pages',
      'comments',
      'theme',
      'plugins',
      'users',
      'management',
      'options',
    );
  }

  public function component_to_slug_callback( $component )
  {
    return $component->slug;
  }
}
