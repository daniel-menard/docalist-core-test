<?php
/**
 * This file is part of a "Docalist Core" plugin.
 *
 * Copyright (C) 2012-2015 Daniel Ménard
 *
 * For copyright and license information, please view the
 * LICENSE.txt file that was distributed with this source code.
 *
 * @package     Docalist
 * @subpackage  Table
 * @author      Daniel Ménard <daniel.menard@laposte.net>
 * @version     SVN: $Id$
 */
namespace Docalist\Table;

use Docalist\Core\Settings;
use Docalist\Cache\FileCache;
use Docalist\Tokenizer;
use PDO;
use Exception;
use InvalidArgumentException;

/**
 * Gestionnaire de tables d'autorité.
 *
 */
class TableManager {

    /**
     * Le logger utilisé.
     *
     * @var LoggerInterface
     */
    protected $log;

    /**
     * Master table (table des tables).
     *
     * @var MasterTable
     */
    protected $master = null;


    /**
     * Liste des tables déclarées.
     *
     * @var TableInfo[] Un tableau d'objets TableInfo indexé par nom.
     */
    protected $tables;

    /**
     * Liste des tables ouvertes.
     *
     * @var TableInterface[] Un tableau d'objets TableInterface indexé par nom.
     */
    protected $opened;

    /**
     * Initialise le gestionnaire de tables.
     */
    public function __construct() {
        // Initialise notre log
        $this->log = docalist('logs')->get('tables');
    }

    /**
     * Déclare une table dans la master table.
     *
     * @param TableInfo $table Propriétés de la table.
     *
     * @throws InvalidArgumentException Si la table est déjà déclarée.
     *
     * @return self
     */
    public function register(TableInfo $table) {
        $this->master()->register($table);

        return $this;
    }

    /**
     * Supprime une table enregistrée dans la master table.
     *
     * Remarque : la table est juste supprimée de la master table, elle
     * n'est pas supprimée du disque.
     *
     * @param string $name
     *
     * @throws InvalidArgumentException Si la table n'est pas déclarée.
     *
     * @return self
     */
    public function unregister($name) {
        $this->master()->unregister($name);

        return $this;
    }

    /**
     * Retourne la table indiquée.
     *
     * @param string $name Nom de la table à retourner.
     *
     * @return TableInterface
     *
     * @throws Exception Si la table indiquée n'a pas été enregistrée.
     */
    public function get($name) {
        if ($name === 'master') {
            return $this->master();
        }

        // Si la table est déjà ouverte, retourne l'instance en cours
        if (isset($this->opened[$name])) {
            return $this->opened[$name];
        }

        // Vérifie que la table demandée a été enregistrée
        $table = $this->table($name);

        // Ouvre la table
        $path = $table->path();
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        switch($extension) {
            case 'sqlite':
            case 'db':
                return $this->opened[$name] = new SQLite($path);

            case 'csv':
            case 'txt':
                return $this->opened[$name] = new CsvTable($path);

            case 'php':
                return $this->opened[$name] = new PhpTable($path);

            default:
                throw new Exception("Unrecognized table type '$extension'");
        }
    }

    /**
     * Crée une nouvelle table personnalisée en recopiant une table existante.
     *
     * @param string $name Nom de la table à recopier.
     * @param string $newName Nom de la table à créer.
     * @param string $label Libellé de la nouvelle table.
     * @param bool $nodata true : recopier uniquement la structure de la table,
     * false : recopier la structure et les données.
     *
     * @return self
     *
     * @throws Exception
     * - si la table $name n'existe pas
     * - si le nom de la nouvelle table n'est pas correct ou n'est pas unique
     * - s'il existe déjà une table $newName
     * - si le répertoire des tables utilisateurs (wp-content/upload/tables)
     *   ne peut pas être créé
     * - si un fichier $newName.txt existe déjà dans ce répertoire
     * - si une erreur survient durant la copie
     */
    public function copy($name, $newName, $label, $nodata) {
        // Vérifie que la table source existe
        $table = $this->table($name);

        // Vérifie que le nouveau nom est correct et unique
        $this->master()->checkName($newName);

        // Détermine le path de la nouvelle table
        $fileName = $newName . '.txt';
        $path = docalist('tables-dir') . DIRECTORY_SEPARATOR . $fileName;

        // Vérifie qu'il n'existe pas déjà un fichier avec ce path
        if (file_exists($path)) {
            $msg = __('Il existe déjà un fichier "%s" dans le répertoire des tables.', 'docalist-core');
            throw new InvalidArgumentException(sprintf($msg, $fileName));
        }

        // Charge la table source
        $source = $this->get($name);
        $fields = $source->fields();

        // Génère le fichier CSV de la nouvelle table
        $file = fopen($path, 'w');
        fputcsv($file, $fields, ';', '"');
        if (! $nodata) {
            $data = $source->search('ROWID,' . implode(',', $fields));
            foreach($data as $entry) {
                fputcsv($file, (array) $entry, ';', '"');
            }
        }
        fclose($file);

        // Crée la structure TableInfo de la nouvelle table
        $table = new TableInfo([
            'name' => $newName,
            'path' => $path,
            'label' => $label,
            'format'=> $table->format(),
            'type' => $table->type(),
            'readonly' => false
        ]);

        // Déclare la nouvelle table
        $this->register($table);

        // Ok
        return $this;
    }

    /**
     * Met à jour les propriétés et/ou le contenu d'une table personnalisée.
     *
     * @param unknown $name
     * @param string $newName
     * @param string $label
     * @param array $data
     *
     * @return self
     *
     * @throws Exception
     * - si la table $name n'existe pas.
     * - si la table $name n'est pas une table personnalisée.
     * - s'il existe déjà une table $newName.txt dans le répertoire des table.
     * - si la table ne peut pas être renommée
     */
    public function update($name, $newName = null, $label = null, array $data = null) {
        // Vérifie que la table à modifier existe
        $table = $this->table($name);

        // Vérifie qu'il s'agit d'une table personnalisée
        if ($table->readonly()) {
            $msg = __('La table "%s" est une table prédéfinie, elle ne peut pas être modifiée.', 'docalist-core');
            throw new Exception(sprintf($msg, $name));
        }

        $path = $table->path();
        ($newName === $name) && $newName = null;
        ($label === $table->label()) && $label = null;

        // Vérifie que le nouveau nom est correct et unique
        $newName && $this->master()->checkName($newName);

        // Mise à jour du contenu de la table
        if ($data) {
            // Récupère la liste des champs
            $fields = $this->get($name)->fields();

            // Ferme la table
            unset($this->opened[$name]);

            // Génère le fichier CSV de la nouvelle table
            $file = fopen($path, 'wb');
            fputcsv($file, $fields, ';', '"');
            foreach($data as $entry) {
                fputcsv($file, (array) $entry, ';', '"');
            }
            fclose($file);
        }

        // Changement de nom
        if ($newName) {
            // Détermine le nouveau path
            $p = pathinfo($path);
            $newPath = $p['dirname'] . DIRECTORY_SEPARATOR . $newName . '.' . $p['extension'];

            // Vérifie qu'il n'existe pas déjà un fichier avec ce nom
            if (file_exists($newPath)) {
                $msg = __('Il existe déjà un fichier "%s" dans le répertoire des tables.', 'docalist-core');
                throw new Exception(sprintf($msg, $newName));
            }

            // Renomme le fichier
            if (! @rename($path, $newPath)) {
                $msg = __('Impossible de renommer la table "%s" en "%s".', 'docalist-core');
                throw new Exception(sprintf($msg, $name, $newName));
            }

            // Supprime l'ancienne table du cache
            $cache = docalist('file-cache'); /* @var $cache FileCache */
            $cache->has($path) && $cache->clear($path);

            // Met à jour le path de la table
            $table->path = $newPath;
        }

        // Met à jour les propriétés de la table (lastupdate notamment)
        if ($data || $newName || $label) {
            $newName && $table->name = $newName;
            $label && $table->label = $label;

            $this->master()->update($this->rowid($name), $table);
        }

        // Ok
        return $this;
    }

    /**
     * Supprime une table personnalisée.
     *
     * @param string $name Nom de la table à supprimer.
     *
     * @return self
     *
     * @throws Exception
     * - si la table $name n'existe pas.
     * - si la table $name n'est pas une table personnalisée.
     * - si la suppression échoue
     */
    public function delete($name) {
        // Vérifie que la table à modifier existe
        $table = $this->table($name);

        // Vérifie qu'il s'agit d'une table personnalisée
        if ($table->readonly()) {
            $msg = __('La table "%s" est une table prédéfinie, elle ne peut pas être modifiée.', 'docalist-core');
            throw new Exception(sprintf($msg, $name));
        }

        // Ferme la table
        unset($this->opened[$name]);

        // Supprime le fichier CSV
        $path = $table->path();
        if (file_exists($path)) {
            if (! @unlink($path)) {
                $msg = __('Impossible de supprimer la table "%s".', 'docalist-core');
                throw new Exception(sprintf($msg, $name));
            }
        }

        // Supprime l'ancienne table du cache
        $cache = docalist('file-cache'); /* @var $cache FileCache */
        $cache->has($path) && $cache->clear($path);

        // Supprime la table de la master table
        $this->unregister($name);

        // Ok
        return $this;
    }

    /**
     * Lookup sur une table d'autorité ou sur un thesaurus.
     *
     * @param string $table La table source.
     *
     * @param string $search le préfixe recherché
     *
     * @param bool $thesaurus indique s'il faut faire une recherche en mode
     * thesaurus ou en mode table.
     *
     * @return array La méthode retourne toujours un tableau (éventuellement
     * vide).
     */
    public function lookup($table, $search, $thesaurus = false) {
        // Par défaut, retourne les 10 premières réponses obtenues
        $limit = 100;

        // Récupère la table
        $table = $this->get($table); /* @var $table TableInterface */

        // Détermine les champs à retourner
        if ($thesaurus) {
            $what = 'code,label,MT,USE,' .
                    "(SELECT group_concat(label,'¤') FROM data d WHERE USE=data.code) AS UF," .
                    'BT,' .
                    "(SELECT group_concat(code, '¤') FROM data d WHERE BT=data.code) AS NT," .
                    'RT,description,sn';
        } else {
            $what = 'code,label,description';
        }

        // Recherche par code
        if (strlen($search) >= 2 && $search[0] === '[' && substr($search, -1) === ']') {
            // Supprime les crochets
            $search = substr($search, 1, -1);

            // Cas particulier chaine vide/null
            if ($search === '') {
                // Inutile de faire du "like"
                $where = '_code IS NULL';

                // On peut avoir plusieurs entrées sans code (non descripteurs)
                // $limit = 10; // par défaut

                // Tri par libellé
                $order = '_label';
            }

            // Cas général, on a un préfixe
            else {
                // Tokenize la chaine pour être insensible aux accents, etc.
                $search = implode(' ', Tokenizer::tokenize($search));

                // Construit la clause de recherche
                $where = '_code=' . $table->quote($search);

                // Les codes sont censés être uniques, donc une seule réponse
                $limit = 1;

                // Et pas de tri
                $order = null;
            }
        }

        // Recherche sur code et libellé
        else {
            // Tokenize la chaine pour être insensible aux accents, etc.
            $search = implode(' ', Tokenizer::tokenize($search));

            // Construit la clause de recherche
            if ($search) {
                $where = sprintf('_code LIKE %1$s OR _label LIKE %1$s', $table->quote($search . '%'));
            } else {
                $where = null;
            }

            // Tri par label insensible à la casse
            $order = '_label';
        }

        // On veut un tableau d'objet, pas un tableau associatif code=>label
        $what = 'ROWID,'.$what;

        // Lance la recherche
        $result = $table->search($what, $where, $order, $limit);

        // En mode "thesaurus", traduit les codes par leur libellé
        if ($thesaurus && $result) {
            $codes = [];
            foreach($result as $term) {
                foreach(['USE', 'MT', 'BT','NT' ,'RT'] as $rel) {
                    if ($term->$rel) {
                        foreach(explode('¤', $term->$rel) as $code) {
                            $codes[$code] = $table->quote($code);
                        }
                    }
                }
                if ($term->UF) {
                    $term->UF = explode('¤', $term->UF);
                }
            }

            if ($codes) {
                $where = 'code IN (' . implode(',', $codes) . ')'; // qoute a été appellé sur chaque code plus haut
                $codes = $table->search('code,label', $where);
                foreach($result as $term) {
                    foreach(['USE', 'MT', 'BT','NT', 'RT'] as $rel) {
                        if ($term->$rel) {
                            $t = [];
                            foreach(explode('¤', $term->$rel) as $code) {
                                if (isset($codes[$code])) {
                                    $t[$code] = $codes[$code];
                                } else {
                                    $t[$code] = 'DESC-ERROR:' . $code;
                                }
                            }
                            $term->$rel = $t;
                        }
                    }
                }
            }
        }

        // Supprime ROWID, pour que json_encode génère bien un tableau
        return array_values($result);
    }

    /**
     * Retourne la master table.
     *
     * @return MasterTable
     */
    protected function master() {
        if (!isset($this->master)) {
            $path = docalist('tables-dir') . DIRECTORY_SEPARATOR . 'master.txt';
            $this->master = new MasterTable($path);
        }

        return $this->master;
    }

    /**
     * Retourne l'ID interne (rowid) de la table passée en paramètre.
     *
     * @param string $name
     *
     * @return int|false L'ID de la table ou false si elle n'existe pas.
     */
    public function rowid($name) {
        return $this->master()->rowid($name);
    }

    /**
     * Teste si la table indiquée est déclarée.
     *
     * @param string $name
     *
     * @return bool
     */
    public function has($name) {
        return $this->master()->has($name);
    }

    /**
     * Retourne les propriétés de la table passée en paramètre.
     *
     * @param string $name Nom de la table.
     *
     * @return TableInfo Les propriétés de la table.
     *
     * @throws InvalidArgumentException Si la table indiquée n'existe pas dans
     * la master table.
     */
    public function table($name) {
        return $this->master()->table($name);
    }

    /**
     * Retourne la liste des tables déclarées dans la master table.
     *
     * Par défaut la méthode retourne toute les tables mais vous pouvez filtrer
     * la liste par type et/ou par format en fournissant des paramètres (si vous
     * indiquez à la fois un type et un format, les deux critères sont combinés
     * en "ET").
     *
     * @param string $type Optionnel, ne retourne que les tables du type indiqué.
     * @param string $format Optionnel, ne retourne que les tables du format
     * indiqué.
     * @param bool $readonly Optionnel, ne retourne que les tables du type
     * indiqué.
     * @param string $sort Optionnel, ordre de tri (par défaut : _label).
     *
     * @return TableInfo[]
     */
    public function tables($type = null, $format = null, $readonly = null, $sort = '_label') {
        return $this->master()->tables($type, $format, $readonly, $sort);
    }

    /**
     * Retourne la liste des types de tables existants.
     *
     * @param string $format Ne retourne que les types du format indiqué.
     *
     * @return string[]
     */
    public function types($format = null) {
        $where = "type != 'master'";
        $format && $where .= ' AND format=' . $this->master()->quote($format);

        return $this->master()->search('DISTINCT type', $where, '_type');
    }

    /**
     * Retourne la liste des formats de tables existants.
     *
     * @param string $type Ne retourne que les formats du type indiqué.
     *
     * @return string[]
     */
    public function formats($type = null) {
        $where = "format != 'master'";
        $type && $where .= ' AND type=' . $this->master()->quote($type);
        return $this->master()->search('DISTINCT format', $where, '_format');
    }
}