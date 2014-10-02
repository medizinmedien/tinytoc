<?php
/*
Plugin Name: tinyTOC for medONLINE
Plugin URI: https://github.com/medizinmedien/tinytoc
GitHub Plugin URI: https://github.com/medizinmedien/tinytoc
Description: Bildet ein Inhaltsverzeichnis (TOC) auf Seiten und Beiträgen ab einer konfigurierbaren Anzahl (z.B. 2) von Überschriften (h1-h6) mittels Shortcode <code>[toc]</code>.
Version: 0.3.1
Author: Arūnas
Author URI: http://wp.tribuna.lt/tiny-toc
License: GPLv2 or later
*/
// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
  echo "Hi there!  I'm just a plugin, not much I can do when called directly.";
  exit;
}

//==========================================================
// load associated files
require_once(plugin_dir_path( __FILE__ ).'tiny_options.php');
//FS: not needed - require_once(plugin_dir_path( __FILE__ ).'tiny_widget.php');

// init tinyConfiguration
$tiny_toc_options = new tiny_toc_options(
  'tiny_toc',
  __('tinyTOC','tiny_toc'),
  __('tinyTOC Options','tiny_toc'),
  array(
    "main" => array(
      'title' => __('Main Settings','tiny_toc'),
      'callback' => '',
      'options' => array(
        'min' => array(
          'title'=>__('Minimum entries for TOC','tiny_toc'),
          'callback' => 'select',
          'args' => array(
            'values' => array(
              2=>2,
              3=>3,
              4=>4,
              5=>5,
              6=>6,
              7=>7,
              8=>8,
              9=>9,
              10=>10,
            )
          )
        ),
        'position' => array(
          'title'=>__('Insert TOC','tiny_toc'),
          'callback' => 'radio',
          'args' => array(
            'values' => array(
              'above' => __('Above the text','tiny_toc'),
              'below' => __('Below the text','tiny_toc'),
              'neither' => __('Do not display automatically','tiny_toc'),
//              'custom' =>
            )
          )
        )
      )
    )
  ),
  array(
    "use_css"=>false,
    "position"=>'above',
    "min"=>3
  ),
  __FILE__
);
$tiny_toc_options->load();
register_activation_hook(__FILE__, array($tiny_toc_options,'add_defaults'));
add_action('admin_init', array($tiny_toc_options,'init') );
add_action('admin_menu', array($tiny_toc_options,'add_page'));

// FS: Prio "100" (last) replaced with "7" (load first!) - otherwise collision
// with an yet unknown plugin (TOC won't be added to content then, and headings
// are not prepared with id's as link anchors):
add_filter( 'the_content', array('tiny_toc','filter'), 7 );

add_shortcode( 'toc', array('tiny_toc','shortcode'));
function get_toc($attr=array()) {return tiny_toc::template($attr);}
function the_toc($attr=array()) {echo tiny_toc::template($attr);}
/* Find all headings and create a TOC */
class tiny_toc {
  static function template($attr=array()) {
    global $post, $tiny_toc_options;
    $min = (isset($attr['min'])&&$attr['min']>0)?$attr['min']:$tiny_toc_options->values['min'];
    $toc = tiny_toc::create($post->post_content,$min);
    return $toc;
  }
  static function shortcode($attr,$content=false) {
    global $post, $tiny_toc_options;
    $min = (isset($attr['min'])&&$attr['min']>0)?$attr['min']:$tiny_toc_options->values['min'];
    $toc = tiny_toc::create( $post->post_content, $min );
    return $toc;
  }
  static function filter( $content ) {
    global $tiny_toc_options;
    $toc = tiny_toc::create( $content, $tiny_toc_options->values['min'] );
    if ($tiny_toc_options->values['position']=='above') {
      $content = $toc.$content;
    } elseif ($tiny_toc_options->values['position']=='below') {
      $content = $content.$toc;
    }
    return $content;
  }

  static function find_parent(&$items,$item) {
    if (sizeof($items)==0) { return 0; }
    $i = 0;
    $parent = false;
    do {
      ++$i;
      $previous = sizeof($items)-$i;
      if ($item->depth>$items[$previous]->depth) {
        $parent = $items[$previous]->db_id;
      }
    } while (!$parent && sizeof($items)-$i > 0);
    if (sizeof($items)-$i == 0) { return 0; }
    $a = 0;
    while ($item->depth - $items[$previous]->depth > 1) {
      ++$a;
      $empty_item = new stdClass();
      $empty_item->text = '';
      $empty_item->name = '';
      $empty_item->depth = $item->depth-$a;
      $empty_item->id = $parent.'-skip'.$a;
      $empty_item->db_id = sizeof($items)+1;
      $empty_item->parent = $parent;
      $empty_item->empty = true;
      $items[] = $empty_item;
      $previous = sizeof($items)-$i;
    }
    return $parent;
  }

  static function parse(&$content) {
    // FS: Add UTF-8 support and prevent garbled umlauts.
    $content = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head><body>'.($content).'</body></html>';
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($content);
    libxml_use_internal_errors(false);
    $xpath = new DOMXPath($dom);
    $tags = $xpath->query('/html/body/*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]');
    $items = array();
    $min_depth = 6;
    $parent = array();
    for( $i=0; $i < $tags->length; ++$i ) {
      $id = $tags->item($i)->getAttribute('id');
      if(!$id) {
        $id = 'h'.$i;
        $tags->item($i)->setAttribute('id',$id);
      }
      $depth = $tags->item($i)->nodeName[1];
      if ($depth<$min_depth) {
        $min_depth = $depth;
      }
      $item = new stdClass();
      $item->text = $tags->item($i)->nodeValue;
      $item->name = $tags->item($i)->nodeName;
      $item->depth =$depth;
      $item->id = $id;
      $item->parent = tiny_toc::find_parent($items,$item);
      $item->db_id = sizeof($items)+1;
      $items[] = $item;
    }
    $text = $xpath->query('/html/body');
    $text = $dom->saveHTML($text->item(0));

    // FS: Quick fix - body element not removed yet:
    $text = str_replace( array( '<body>', '</body>' ), '', $text );

    $content = $text; // FS: Hier wird *jeder* Content-Bereich ueberschrieben :-(
    return $items;
  }

  static function create(&$content, $min) {
    $items = tiny_toc::parse($content);
    $output = '';
    if (sizeof($items)>=$min) {
      $walker = new tiny_toc_walker();
      $output = $walker->walk($items,0);
      $output = "<nav class=\"tiny_toc\">\n<h4 id=\"tinytoc\">Inhalt</h4>\n<ol>\n{$output}</ol>\n</nav>\n\n";
    }
    return $output;
  }
}

class tiny_toc_walker extends Walker {
  var $db_fields = array(
    'parent' => 'parent',
    'id' => 'db_id'
  );
  function start_lvl(&$output, $depth = 0, $args = array()) {
    $output .= "\n<ol>\n";
  }
  function start_el( &$output, $object, $depth = 0, $args = array(), $current_object_id = 0 ) {
    $output .= '<li>';
    if (isset($object->empty) && $object->empty) {
    } else {
      $output .= "<a href=\"#{$object->id}\">{$object->text}</a>";
    }
  }
  function end_el( &$output, $object, $depth = 0, $args = array() ) {
    $output .= "</li>\n";
  }
  function end_lvl(&$output,$depth=0,$args=array()) {
    $output .= "</ol>\n";
  }
}

/**
 * Set on-the-fly backlinks from headings to tinyTOC when present in content.
 *
 * FS - 30.09.2014 - frank@hostz.at
 */
add_action( 'wp_footer', 'tiny_toc_add_backlinks_to_headings' );
function tiny_toc_add_backlinks_to_headings() {
	global $post;
	if( is_singular() && ( strpos( $post->post_content, '[toc]' ) !== false || strpos( $post->post_content, '#tinytoc') ) ) {
		$backlink = '<span class="backlink2toc"><a href="#tinytoc">&#10548;</a></span>';
		$backlink = apply_filters( 'tiny_toc_backlink', $backlink );

		$output  = '<script type="text/javascript" id="tiny_toc">';
		$output .=   'jQuery(document).ready(function($){';

		$output .=     'headers=$("h2[id^=h],h3[id^=h],h4[id^=h],h5[id^=h],h6[id^=h]");';
		$output .=     'is_toc=$("#tinytoc").size()>0;';

		$output .=     'if(is_toc && headers.size()>0){';
		$output .=       'headers.each(function(){';
		$output .=         "$(this).append('$backlink')";
		$output .=       '});';
		$output .=     '}';

		$output .=   '});';
		$output .= '</script>';

		echo $output;
	}
}

