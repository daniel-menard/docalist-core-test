<?php
/**
 * This file is part of the "Docalist Forms" package.
 *
 * Copyright (C) 2012,2013 Daniel Ménard
 *
 * For copyright and license information, please view the
 * LICENSE.txt file that was distributed with this source code.
 *
 * @package     Docalist
 * @subpackage  Forms
 * @author      Daniel Ménard <daniel.menard@laposte.net>
 * @version     SVN: $Id$
 */

namespace Docalist\Forms;

use Exception;

/**
 * Gère la liste des thèmes disponibles pour le rendu des formulaires.
 */
class Themes {
    /**
     * @var array Liste des thèmes enregistrés. Le tableau a la structure
     * suivante :
     *
     * 'nom-du-theme' => array
     * (
     *     'path' => répertoire absolu du thème
     *     'extends' => nom du thème de base de ce thème
     *     'assets' => liste des css et des js requis pour ce thème = array
     * )
     *
     */
    protected static $themes = array();

    /**
     * Vérifie que le thème indiqué existe.
     *
     * @param string $name le nom du thème à vérifier.
     *
     * @throws Exception Si le thème indiqué n'existe pas.
     */
    private static function check($name) {
        if (!isset(self::$themes[$name])) {
            $msg = __('Le thème %s n\'existe pas.', 'docalist-core');
            throw new Exception(sprintf($msg, $name));
        }

    }

    /**
     * Enregistre un nouveau thème utilisable pour le rendu des formulaires.
     *
     * @param string $name Le nom symbolique du thème.
     *
     * @param string $path Le path du répertoire contenant les templates du
     * thème. Il doit s'agit d'un chemin absolu.
     *
     * @param string $extends Nom du thème de base de ce thème.
     *
     * @param array $assets Les assets (fichiers css et javascript) requis pas
     * ce thème. La tableau passé en paramètre doit avoir le même format que
     * les assets décrits dans la méthode {@link Field::assets()}.
     *
     * @throws Exception Si le thème indiqué est déjà enregistré.
     */
    public static function register($name, $path, $extends = 'default', $assets = null) {
        if (WP_DEBUG && isset(self::$themes[$name])) {
            $msg = __('Le thème %s est déjà enregistré.', 'docalist-core');
            throw new Exception(sprintf($msg, $name));
        }

        // Garantit que le path contient toujours un slash final
        $path = rtrim($path, '\\/') . '/';

        // Vérifie que le thème de base existe
        WP_DEBUG && ($extends !== false) && self::check($extends);

        // Normalise les assets
        // @todo

        // Stocke le tout
        self::$themes[$name] = array(
            'path' => $path,
            'extends' => $extends,
            'assets' => $assets,
        );
    }

    /**
     * Enregistre les thèmes par défaut du package.
     */
    public static function registerDefaultThemes() {
        // on ne peut pas initialiser directement la propriété lors de sa
        // déclaration parce qu'on utilise __DIR__ et une concaténation et
        // php ne supporte pas ça.
        $dir = dirname(__DIR__) . '/themes/';
        self::$themes = array(
            'default' => array(
                'path' => $dir . 'default/',
                'extends' => false,
                'assets' => array(
                    array(
                        'type' => 'js',
                        'src' => '//ajax.googleapis.com/ajax/libs/jquery/1.9.0/jquery.min.js',
                    ),
/*
                    array(
                        'type' => 'css',
                        'src' => '//netdna.bootstrapcdn.com/twitter-bootstrap/2.2.2/css/bootstrap-combined.min.css',
                    )
 */
                ),
            ),
            'dump' => array(
                'path' => $dir . 'dump/',
                'extends' => false,
                'assets' => null,
            ),
            'form-table' => array(
                'path' => $dir . 'form-table/',
                'extends' => false,
                'assets' => null,
            ),
        );
    }

    /**
     * Retourne le répertoire de base d'un thème.
     *
     * @param string $theme Le nom du thème recherché.
     *
     * @return string Retourne le path absolu du répertoire contenant
     * les templates du thème indiqué.
     *
     * @throws Exception Si le thème indiqué n'existe pas.
     */
    public static function path($name) {
        WP_DEBUG && self::check($name);

        return self::$themes[$name]['path'];
    }

    /**
     * Retourne le thème de base d'un thème.
     *
     * @param string $theme Le nom du thème recherché.
     *
     * @return string
     *
     * @throws Exception Si le thème indiqué n'existe pas.
     */
    public static function baseTheme($name) {
        WP_DEBUG && self::check($name);

        return self::$themes[$name]['extends'];
    }

    /**
     * Retourne les assets d'un thème.
     *
     * @param string $theme Le nom du thème recherché.
     *
     * @return array
     *
     * @throws Exception Si le thème indiqué n'existe pas.
     */
    public static function assets($name) {
        WP_DEBUG && self::check($name);

        return self::$themes[$name]['assets'];
    }

    /**
     * Retourne les noms des thèmes enregistrés.
     *
     * @return array Un tableau de la forme nom du thème = path
     */
    public static function all() {
        return array_keys(self::$themes);
    }

    /**
     * Recherche un fichier au sein d'un thème.
     *
     * La méthode teste si le fichier indiqué figure dans le répertoire du
     * thème dont le nom est passé en paramètre. Si c'est le cas elle retourne
     * son path, sinon elle recommence avec le thème parent du thème et ainsi
     * de suite.
     *
     * @param string $theme Nom du thème.
     *
     * @param string $file le fichier recherché.
     *
     * @return string|false le path du fichier ou false si le fichier est
     * introuvable.
     */
    public static function search($theme, $file) {
        do {
            $path = self::$themes[$theme]['path'] . $file;
            if (file_exists($path))
                return $path;
            $theme = self::$themes[$theme]['extends'];
        } while ($theme !== false);

        return false;

    }

}

// @todo Dirty, mais pas de "static initializers" en php...
// alternatives : singleton, autoloader qui appelle __init() ?
Themes::registerDefaultThemes();