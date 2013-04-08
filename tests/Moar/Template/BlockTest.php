<?php
/**
 * @package Moar\Metrics
 */

namespace Moar\Template;

/**
 * @package Moar\Metrics
 * @copyright 2013 Bryan Davis and contributors. All Rights Reserved.
 */
class BlockTest extends \PHPUnit_Framework_TestCase {

  public function test_only_base () {
    $this->assertExpected('base');
  }

  public function test_inheritance () {
    $this->assertExpected('page1');
  }

  protected function assertExpected ($name) {
    $want = $this->getExpect($name);
    $got = $this->evalTemplate($name);
    $this->assertEquals($want, $got);
  }

  protected function getExpect ($name) {
    return trim(
        file_get_contents(dirname(__FILE__) . "/fixtures/{$name}.out"));
  }

  protected function evalTemplate ($name) {
    ob_start();
    require dirname(__FILE__) . "/fixtures/{$name}.php";
    Block::flush();
    $out = ob_get_contents();
    ob_end_clean();
    return $out;
  }

} //end BlockTest
