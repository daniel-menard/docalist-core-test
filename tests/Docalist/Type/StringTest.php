<?php
/**
 * This file is part of the "Docalist Core" plugin.
 *
 * Copyright (C) 2012-2014 Daniel Ménard
 *
 * For copyright and license information, please view the
 * LICENSE.txt file that was distributed with this source code.
 *
 * @package     Docalist
 * @subpackage  Tests
 * @author      Daniel Ménard <daniel.menard@laposte.net>
 * @version     SVN: $Id$
 */

namespace Docalist\Tests\Type;

use WP_UnitTestCase;

use Docalist\Type\String;

class StringTest extends WP_UnitTestCase {
    public function testNew() {
        $a = new String();
        $this->assertSame('', $a->value());
    }

    /** @expectedException Docalist\Type\Exception\InvalidTypeException */
    public function testInvalidType()
    {
        new String([]);
    }
}