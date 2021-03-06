<?php
/**
 * This file is part of a "Docalist Core" plugin.
 *
 * Copyright (C) 2012-2014 Daniel Ménard
 *
 * For copyright and license information, please view the
 * LICENSE.txt file that was distributed with this source code.
 *
 * @package     Docalist
 * @subpackage  Core
 * @author      Daniel Ménard <daniel.menard@laposte.net>
 * @version     SVN: $Id$
 */
namespace Docalist\Repository\Exception;

/**
 * Exception générée lorsqu'une entité ne peut pas être chargée.
 */
class EntityNotFoundException extends RepositoryException {
    /**
     * Construit l'exception.
     *
     * @param scalar $id Identifiant de l'entité..
     */
    public function __construct($id) {
        $msg = __('Entity %s not found', 'docalist-core');
        parent::__construct(sprintf($msg, $id));
    }
}