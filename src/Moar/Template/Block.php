<?php
/**
 * @package Moar\Metrics
 */

namespace Moar\Template;

/**
 * Block based, inheritance driven template engine.
 *
 * Based on Adam Shaw's phpti template engine (http://phpti.com/).
 *
 * @package Moar\Metrics
 * @copyright 2013 Bryan Davis and contributors. All Rights Reserved.
 */
class Block {

  /**
   * Base template information.
   * @var array
   */
  protected static $root = null;

  /**
   * Blocks that have been started but not yet closed.
   * @var array
   */
  protected static $partials = null;

  /**
   * List of named blocks.
   * @var array
   */
  protected static $blocks = null;

  /**
   * Position of last block end in buffered output.
   * @var int
   */
  protected static $end = null;

  /**
   * Start a block.
   *
   * A block defines a section of replacable content. The last definition of
   * a named block will be output by the script. All prior instances of the
   * same block (and any sub blocks) will be discarded.
   *
   * @param string $name Block name
   * @param array|string $filters Filters to apply to block output
   * @return void
   * @see endblock
   */
  public static function block ($name, $filters=null) {
    static::$partials[] = static::create($name, $filters, static::init());
  }

  /**
   * End a block.
   *
   * @param string $name Block name
   * @return void
   */
  public static function endblock ($name=null) {
    $trace = static::init();
    if (static::$partials) {
      $block = array_pop(static::$partials);
      if (null !== $name && $name != $block->name) {
        static::warning(
            "block('{$block->name}') does not match endblock('{$name}')",
            $trace);
      }
      static::insertBlock($block);
    } else {
      static::warning(
          ($name)? "orphan endblock('{$name}')": "orphan endblock()",
          $trace);
    }
  } //end endblock

  /**
   * Define an empty block.
   *
   * An empty block has no contents in the template in which it is defined
   * but can be overloaded in a descendant template to output content.
   *
   * @param string $name Block name
   * @return void
   */
  public static function emptyblock ($name) {
    static::insertBlock(static::create($name, null, static::init()));
  }

  /**
   * Output the content of the parent block.
   *
   * Used within a block to include the complete content of the parent block.
   *
   * @return void
   */
  public static function super () {
    if (static::$partials) {
      echo static::superAsString();
    } else {
      static::warning("super() call must be within a block", static::bt());
    }
  }

  /**
   * Get the content of the parent block as a string.
   *
   * @return string Block content
   * @see superblock
   */
  public static function superAsString () {
    $out = '';
    if (static::$partials) {
      $block = end(static::$partials);
      if (isset(static::$blocks[$block->name])) {
        $out = implode('', static::compile(
            static::$blocks[$block->name]->block, ob_get_contents()));
      }
    } else {
      static::warning(
          "superAsString() call must be within a block", static::bt());
    }
    return $out;
  }

  /**
   * Flush all output buffers.
   *
   * @return void
   */
  public static function flush () {
    if (static::$root) {
      while ($block = array_pop(static::$partials)) {
        static::warning("missing endblock() for block('{$block->name}')",
            static::bt(), $block->trace);
      }
      while (ob_get_level() > static::$root->oblevel) {
        ob_end_flush(); // will eventually trigger bufferCallback
      }
      static::$root = null;
      static::$partials = null;
    }
  }

  /**
   * Initialize template engine.
   *
   * @param array $trace Backtrace
   * @return array Backtrace
   */
  protected static function init ($trace=null) {
    if (null === $trace) {
      $trace = static::bt();
    }
    if (static::$root && !static::inRootOrChild($trace)) {
      flushblocks(); // will set $root to null
    }
    if (null === static::$root) {
      static::$root = (object) array(
          'trace' => $trace,
          'filters' => null,
          'kids' => array(),
          'start' => 0,
          'end' => null,
          'oblevel' => ob_get_level(),
        );
      static::$partials = array();
      static::$blocks = array();
      static::$end = null;
      // start buffering output
      ob_start(array(__CLASS__, 'bufferCallback'));
    }
    return $trace;
  } //end init

  /**
   * Create a block.
   *
   * @param string $name Block name
   * @param mixed $filters Filters to apply to block output
   * @param array $trace Backtrace of block declaration
   * @return object Block header
   */
  protected static function create ($name, $filters, $trace) {
    while ($block = end(static::$partials)) {
      if (static::sameFile($block->trace, $trace)) {
        // block is defined in same file as open block
        break;

      } else {
        // open block from another file
        // remove and close open block
        array_pop(static::$partials);
        static::insertBlock($block);
        static::warning("missing endblock() for block('{$block->name}')",
            static::bt(), $block->trace);
      }
    }

    if (null === static::$root->end && !static::inRoot($trace)) {
      // mark end of root template in output buffer
      static::$root->end = ob_get_length();
    }

    if ($filters) {
      if (is_string($filters)) {
        $filters = preg_split('/\s*[,|]\s*/', trim($filters));

      } else if (!is_array($filters)) {
        $filters = array($filters);
      }

      foreach ($filters as $i => $f) {
        if ($f && !is_callable($f)) {
          $fname = (is_array($f))? implode('::', $f): $f;
          static::warning("filter {$fname} is not defined", $trace);
          $filters[$i] = null;
        }
      }
    } //end if filters

    // create and return block header
    return (object) array(
        'name' => $name,
        'trace' => $trace,
        'filters' => $filters,
        'kids' => array(),
        'start' => ob_get_length(),
      );
  } //end create

  /**
   * Insert a block into the template stack.
   *
   * @param object $block Block header
   * @return void
   */
  protected static function insertBlock ($block) {
    // close the block and track globally
    $block->end = static::$end = ob_get_length();
    $name = $block->name;
    if (static::$partials || static::inRoot($block->trace)) {
      // make an anchor to keep track of block extents
      $anchor = (object) array(
          'start' => $block->start,
          'end' => static::$end,
          'block' => $block,
        );

      if (static::$partials) {
        // add nested block as a child of last partial
        static::$partials[count(static::$partials) - 1]->kids[] = $anchor;

      } else {
        // top-level block in base
        static::$root->kids[] = $anchor;
      }
      // track block by name
      static::$blocks[$name] = $anchor;

    } else if (isset(static::$blocks[$name])) {
      if (static::sameFile(
          static::$blocks[$name]->block->trace, $block->trace)) {
        static::warning("cannot define another block called '{$name}'",
            static::bt(), $block->trace);
      } else {
        // top-level block in a child template; override the base's block
        static::$blocks[$name]->block = $block;
      }
    }
  } //end insertBlock

  /**
   * Output buffering callback.
   *
   * Triggered when the template's master buffer is flushed.
   *
   * @param string $buffer Buffered content
   * @return string Buffer content
   */
  public static function bufferCallback ($buffer) {
    if (static::$root) {
      while ($block = array_pop(static::$partials)) {
        // close all unclosed blocks
        static::insertBlock($block);
        static::warning("missing endblock() for block('{$block->name}')",
            static::bt(), $block->trace);
      }

      if (null === static::$root->end) {
        // root template ends at end of buffer (no inheritance)
        static::$root->end = strlen($buffer);
        // means there were no blocks other than the base's
        static::$end = null;
      }

      $chunks = static::compile(static::$root, $buffer);
      // remove trailing whitespace from last chunk
      $chunks[] = rtrim(array_pop($chunks));

      // if there are child template blocks, preserve output after last one
      if (null !== static::$end) {
        $chunks[] = substr($buffer, static::$end);
      }

      return implode('', $chunks);

    } else {
      // no blocks defined. Abort output.
      return '';
    }
  } //end bufferCallback

  /**
   * Compile a block.
   *
   * @param object $block Block header
   * @param string $buffer Output buffer contents
   * @return array Block contents
   */
  protected static function compile ($block, $buffer) {
    $chunks = array();
    $ptr = $block->start;
    foreach ($block->kids as $kid) {
      // add output from last offset to start of next block
      $chunks[] = substr($buffer, $ptr, $kid->start - $ptr);
      // compile next block
      $chunks = array_merge($chunks, static::compile($kid->block, $buffer));
      // advance pointer past block
      $ptr = $kid->end;
    }
    if ($ptr != $block->end) {
      // could be a big buffer, so only do substr if necessary
      $chunks[] = substr($buffer, $ptr, $block->end - $ptr);
    }
    if ($block->filters) {
      $out = implode('', $chunks);
      foreach ($block->filters as $filter) {
        if ($filter) {
          $out = call_user_func($filter, $out);
        }
      }
      $chunks = array($out);
    }
    return $chunks;
  } //end compile

  /**
   * Log a compilation warning.
   *
   * @param string $message Warning message
   * @param array $trace Backtrace of interpreter
   * @param array $loc Backtrace of error
   * @return void
   */
  protected static function warning ($message, $trace, $loc=null) {
    static $log;
    if (null === $log) {
      $log = \Moar\Log\LoggerFactory::getLogger(__CLASS__);
    }
    if (!$loc) {
      $loc = $trace;
    }
    $log->warning(
        "{$message} in {$loc[0]['file']} on line {$loc[0]['line']}");
  } //end warning

  /**
   * Get the backtrace minus calls local to this class.
   *
   * @return array Backtrace
   * @see debug_backtrace
   */
  protected static function bt () {
    $trace = debug_backtrace();
    foreach ($trace as $i => $location) {
      if (__FILE__ !== $location['file']) {
        $trace = array_slice($trace, $i);
        break;
      }
    }
    return $trace;
  }

  /**
   * Is the trace from the same file as the root template?
   *
   * @param array $trace Backtrace
   * @return bool True if trace is from same file as root template.
   */
  protected static function inRoot ($trace) {
    return static::sameFile($trace, static::$root->trace);
  }

  protected static function inRootOrChild ($trace) {
    $origin = static::$root->trace;
    return $trace && $origin &&
        static::isSubtrace(array_slice($trace, 1), $origin) &&
        $trace[0]['file'] === $origin[count($origin) - count($trace)]['file'];
  }

  /**
   * Are the two traces from the same file?
   *
   * @param array $trace1 Backtrace
   * @param array $trace2 Backtrace
   * @return bool True if both traces are from the same php file.
   */
  protected static function sameFile ($trace1, $trace2) {
    return $trace1 && $trace2 &&
        $trace1[0]['file'] === $trace2[0]['file'] &&
        array_slice($trace1, 1) === array_slice($trace2, 1);
  }

  /**
   * Is one trace a subset of another?
   * @retrun bool True if $trace1 is contained in $trace2
   */
  protected static function isSubtrace ($trace1, $trace2) {
    $len1 = count($trace1);
    $len2 = count($trace2);
    if ($len1 > $len2) {
      return false;
    }
    for ($i = 0; $i < $len1; $i++) {
      if ($trace1[$len1 - 1 - $i] !== $trace2[$len2 - 1 - $i]) {
        return false;
      }
    }
    return true;
  } //end isSubtrace

} //end Block
