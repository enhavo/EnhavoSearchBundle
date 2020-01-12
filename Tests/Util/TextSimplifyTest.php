<?php
/**
 * Created by PhpStorm.
 * User: gseidel
 * Date: 24.08.18
 * Time: 02:11
 */

namespace Enhavo\Bundle\SearchBundle\Util;
use PHPUnit\Framework\TestCase;

class TextSimplifyTest extends TestCase
{
    public function testSimplify()
    {
        $textSimplify = new TextSimplify();

        $this->assertEquals('lorem ipsum dolor', $textSimplify->simplify('Lorem ipsum! Dolor'));
        $this->assertEquals('lorem ipsum dolor', $textSimplify->simplify('Lorem   ipsum!!! Dolor'));
        $this->assertEquals('lorem ipsum dolor', $textSimplify->simplify('"Lorem" "ipsum" .Dolor?'));
        $this->assertEquals('lorem ipsum dolor', $textSimplify->simplify('"Lorem" (ipsum) .Dolor?'));
        $this->assertEquals('loräm ipsum dolör', $textSimplify->simplify('"LorÄm" ipsum dolör'));
    }
}