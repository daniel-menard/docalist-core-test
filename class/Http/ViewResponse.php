<?php
/**
 * This file is part of the 'Docalist Core' plugin.
 *
 * Copyright (C) 2012, 2013 Daniel Ménard
 *
 * For copyright and license information, please view the
 * LICENSE.txt file that was distributed with this source code.
 *
 * @package     Docalist
 * @subpackage  Response
 * @author      Daniel Ménard <daniel.menard@laposte.net>
 * @version     $Id$
 */
namespace Docalist\Http;

/**
 * Une réponse dont le résultat est généré par une vue.
 */
class ViewResponse extends HtmlResponse {
    protected $view;
    protected $data;

    public function __construct($view, array $data = [], $status = 200, $headers = array()) {
        parent::__construct(null, $status, $headers);

        $this->view = $view;
        $this->data = $data;
    }

    public function sendContent() {
        docalist('views')->display($this->view, $this->data);

        return $this;
    }

    public function getContent() {
        return docalist('views')->render($this->view, $this->data);
    }
}