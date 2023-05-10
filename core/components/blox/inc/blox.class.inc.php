<?php

/**
 * bloX
 *
 * Copyright 2009-2013 by Bruno Perner <b.perner@gmx.de>
 *
 * bloX is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any
 * later version.
 *
 * bloX is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with
 * bloX; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package blox
 * @subpackage classfile
 */
class blox
{

    // Declaring private variables
    var $bloxconfig;
    var $bloxtpl;

    // Class constructor
    function blox($bloxconfig)
    {
        $this->bloxID = $bloxconfig['id'];
        $this->bloxconfig = $bloxconfig;
        $this->bloxconfig['prefilter'] = '';
        $this->bloxdebug = array();
        $this->columnNames = array();
        $this->tvnames = array();
        $this->docColumnNames = array();
        $this->tvids = array();
        //$this->bloxconfig['parents'] = $this->cleanIDs($bloxconfig['parents']);
        //$this->bloxconfig['IDs'] = $this->cleanIDs($bloxconfig['IDs']);

        $this->tpls = array();
        $this->checktpls();

        $this->renderdepth = 0;
        $this->eventscount = array();
        $this->output = '';
        $this->date = xetadodb_mktime(0, 0, 0, $this->bloxconfig['month'], $this->bloxconfig['day'], $this->bloxconfig['year']);
    }

    function checktpls()
    {
        // example: &tpls=`bloxouter:myouter||row:contentonly`

        $this->tpls['bloxouter'] = "@FILE " . $this->bloxconfig['tplpath'] . "bloxouterTpl.html"; // [ path | chunkname | text ]
        if ($this->bloxconfig['tpls'] !== '') {
            $tpls = explode('||', $this->bloxconfig['tpls']);
            foreach ($tpls as $tpl) {
                $this->tpls[substr($tpl, 0, strpos($tpl, ':'))] = substr($tpl, strpos($tpl, ':') + 1);
                //Todo: check if chunk exists
            }
        }
    }

    function prepareQuery($scriptProperties = array(), &$total = 0, $forcounting = false)
    {
        global $modx;

        $limit = $modx->getOption('limit', $scriptProperties, '0');
        $offset = $modx->getOption('offset', $scriptProperties, 0);

        $selectfields = $modx->getOption('selectfields', $scriptProperties, '');
        $where = $modx->getOption('where', $scriptProperties, '');
        $where = !empty($where) ? $modx->fromJSON($where) : array();
        $queries = $modx->getOption('queries', $scriptProperties, '');
        $queries = !empty($queries) ? $modx->fromJSON($queries) : array();
        $sortConfig = $modx->getOption('sortConfig', $scriptProperties, '');
        $sortConfig = !empty($sortConfig) ? $modx->fromJSON($sortConfig) : array();
        $groupby = $modx->getOption('groupby', $scriptProperties, '');

        $debug = $modx->getOption('debug', $scriptProperties, false);
        $joins = $modx->getOption('joins', $scriptProperties, '');
        $joins = !empty($joins) ? $modx->fromJson($joins) : false;
        $classname = ($scriptProperties['classname'] != '') ? $scriptProperties['classname'] : 'modResource';
        $c = $modx->newQuery($classname);

        $selectfields = !empty($selectfields) ? explode(',', $selectfields) : null;
        if ($forcounting) {
            $c->select('1');
        } else {
            $c->select($modx->getSelectColumns($classname, $classname, '', $selectfields));
        }

        if ($joins) {
            $this->prepareJoins($classname, $joins, $c, $forcounting);
        }

        if (is_array($where)) {
            $c->where($where);
        }

        if (is_array($queries)) {
            $keys = array('AND' => xPDOQuery::SQL_AND, 'OR' => xPDOQuery::SQL_OR);
            foreach ($queries as $query) {
                $c->where($query['query'], $keys[$query['operator']]);
            }
        }

        if (!empty($groupby)) {
            $c->groupby($groupby);
        }

        if ($forcounting) {
            if ($debug) {
                $c->prepare();
                $this->bloxdebug['config'][] = 'Precount Query String:' . "\r\n" . $c->toSql();
            }
            if ($c->prepare() && $c->stmt->execute()) {
                $rows = $c->stmt->fetchAll(PDO::FETCH_COLUMN);
                $total = count($rows);
            }
            return $c;
        }

        $total = $modx->getCount($classname, $c);

        if (is_array($sortConfig)) {
            foreach ($sortConfig as $sort) {
                $sortby = $sort['sortby'];
                $sortdir = $sort['sortdir'] ?? 'ASC';
                $c->sortby($sortby, $sortdir);
            }
        }
        if (!empty($limit)) {
            $c->limit($limit, $offset);
        }

        if ($debug) {
            $c->prepare();
            $this->bloxdebug['config'][] = 'Query String:' . "\r\n" . $c->toSql();
        }

        return $c;
    }

    public function prepareJoins($classname, $joins, &$c, $forcounting = false)
    {
        global $modx;

        if (is_array($joins)) {
            foreach ($joins as $join) {
                $jalias = $modx->getOption('alias', $join, '');
                $type = $modx->getOption('type', $join, 'left');
                $joinclass = $modx->getOption('classname', $join, '');
                $on = $modx->getOption('on', $join);
                $selectfields = $modx->getOption('selectfields', $join, '');
                if (!empty($jalias)) {
                    if (empty($joinclass) && $fkMeta = $modx->getFKDefinition($classname, $jalias)) {
                        $joinclass = $fkMeta['class'];
                    }
                    if (!empty($joinclass)) {

                        /*
                        if ($joinFkMeta = $modx->getFKDefinition($joinclass, 'Resource')){
                        $localkey = $joinFkMeta['local'];
                        }
                        */

                        $selectfields = !empty($selectfields) ? explode(',', $selectfields) : null;
                        switch ($type) {
                            case 'right':
                                $c->rightjoin($joinclass, $jalias, $on);
                                break;
                            case 'inner':
                                $c->innerjoin($joinclass, $jalias, $on);
                                break;
                            case 'left':
                            default:
                                $c->leftjoin($joinclass, $jalias, $on);
                                break;
                        }
                        if ($forcounting) {

                        } else {
                            $c->select($modx->getSelectColumns($joinclass, $jalias, $jalias . '_', $selectfields));
                        }
                    }
                }
            }
        }
    }

    //////////////////////////////////////////////////
    //Display bloX
    /////////////////////////////////////////////////

    function displayblox()
    {

        $datas = $this->getdatas($this->date, $this->bloxconfig['includesfile']);
        if (isset($datas['bloxoutput'])) {
            //direct output
            $output = $datas['bloxoutput'];
        } else {
            if ($this->bloxconfig['parseFast']) {
                $output = $this->displaydatasFast($datas);
            } else {
                $output = $this->displaydatas($datas);
            }

        }

        return $output;
    }

    //////////////////////////////////////////////////
    //displaydatas (bloxouterTpl)
    /////////////////////////////////////////////////

    function displaydatas($outerdata = array())
    {
        global $modx;

        // $outerdata['innerrows']['row']='innerrows.row';

        $start = microtime(true);
        $cache = $modx->getOption('cacheaction', $outerdata, '');
        $cachename = $modx->getOption('cachename', $outerdata, '');
        if ($cache == '2') {
            return $outerdata['cacheoutput'];
        }

        $bloxouterTplData = array();
        $bloxinnerrows = array();
        $bloxinnercounts = array();

        $innerrows = $modx->getOption('innerrows', $outerdata, array());

        unset($outerdata['innerrows']);

        if (count($innerrows) > 0) {
            foreach ($innerrows as $key => $row) {
                $startsub = microtime(true);
                //$daten = '';
                $innertpl = $this->getTpl($key);
                if ($innertpl !== '') {
                    $data = $this->renderdatarows($row, $innertpl, $key, $outerdata);
                    $bloxinnerrows[$key] = $data;
                    $bloxinnercounts[$key] = count($row);
                }
                $endsub = microtime(true);
                if ($this->bloxconfig['debug'] || $this->bloxconfig['debugTime']) {
                    $this->bloxdebug['time'][] = 'Time to render (' . $key . '): ' . ($endsub - $startsub) . ' seconds';
                }
            }
        }
        $outerdata['innerrows'] = $bloxinnerrows;
        $outerdata['innercounts'] = $bloxinnercounts;

        $bloxouterTplData['row'] = $outerdata;
        $bloxouterTplData['config'] = $this->bloxconfig;
        $outerdata['blox'] = $bloxouterTplData;

        $tpl = new bloxChunkie($this->tpls['bloxouter'], array('parseLazy' => $this->bloxconfig['parseLazy']));
        $tpl->placeholders = $outerdata;
        $daten = $tpl->Render($this->bloxconfig['parseLazy']);
        unset($tpl);
        if ($cache == '1') {
            $this->cache->writeCache($cachename, $daten);
        }

        $end = microtime(true);
        if ($this->bloxconfig['debug'] || $this->bloxconfig['debugTime']) {
            $this->bloxdebug['time'][] = 'Time to render all: ' . ($end - $start) . ' seconds';
        }
        return $daten;
    }

    //////////////////////////////////////////////////
    //displaydatas (bloxouterTpl)
    /////////////////////////////////////////////////

    function displaydatasFast($outerdata = array())
    {
        global $modx;

        $start = microtime(true);

        $searches = array();
        $replaces = array();

        $cache = $modx->getOption('cacheaction', $outerdata, '');
        $cachename = $modx->getOption('cachename', $outerdata, '');
        if ($cache == '2') {
            return $outerdata['cacheoutput'];
        }

        $this->parser = new bloxChunkie($this->tpls['bloxouter'], array('parseLazy' => $this->bloxconfig['parseLazy']));
        $this->parser->maxdepth = 20;
        $this->parser->CreateVars($outerdata);
        $innerrows = $modx->getOption('innerrows', $outerdata, array());
        $embeds = $modx->getOption('embeds', $outerdata, array());

        $this->parser->template = $this->replaceEmbeds($embeds, $this->parser->template);

        unset($outerdata['innerrows'], $outerdata['embeds']);

        if (count($innerrows) > 0) {
            foreach ($innerrows as $key => $row) {
                $startsub = microtime(true);
                $innertpl = $this->getTpl($key);

                if ($innertpl !== '') {
                    $data = $this->renderdatarows($row, $innertpl, $key, $outerdata);
                    $searches[] = '[[+innerrows.' . $key . ']]';
                    $replaces[] = $data;
                }
                $endsub = microtime(true);
                if ($this->bloxconfig['debug'] || $this->bloxconfig['debugTime']) {
                    $this->bloxdebug['time'][] = 'Time to render (' . $key . '): ' . ($endsub - $startsub) . ' seconds';
                }
            }
        }

        $this->parser->placeholders['blox.config'] = $this->bloxconfig;
        $this->parser->placeholders['blox.userID'] = $this->bloxconfig['userID'];
        $this->parser->placeholders['blox.date'] = $this->date;

        //replace innerrows
        $output = str_replace($searches, $replaces, $this->parser->template);
        //replace fastParse - placeholders also in bloxouter - template
        $output = str_replace($this->bloxconfig['fastParseTag'], '[[+', $output);

        //echo str_replace('[[+','[ [+',$daten);
        //echo '<pre>' . print_r($this->parser->getPlaceholders(), 1) . '</pre>';
        $this->parser->template = $output;
        $output = $this->parser->Render($this->bloxconfig['parseLazy']);
        unset($this->parser);
        if ($cache == '1') {
            $this->cache->writeCache($cachename, $output);
        }

        $end = microtime(true);
        if ($this->bloxconfig['debug'] || $this->bloxconfig['debugTime']) {
            $this->bloxdebug['time'][] = 'Time to render all: ' . ($end - $start) . ' seconds';
        }
        return $output;
    }

    //////////////////////////////////////////////////
    //renderdatarows
    /////////////////////////////////////////////////
    function renderdatarows($rows, $tpl, $rowkey = '', $outerdata = array(), $outerpath = '', $fast = false)
    {
        //$this->renderdepth++;//Todo

        $output = '';
        $out = array();
        if (is_array($rows)) {
            $iteration = 1;
            $rowscount = count($rows);

            foreach ($rows as $row) {
                $keypath = $outerpath . 'innerrows.' . $rowkey . '.' . $iteration . '.';
                if ($this->bloxconfig['parseFast']) {
                    $out[] = $this->renderdatarowFast($row, $tpl, $rowkey, $outerdata, $rowscount, $iteration, $keypath, $outerpath);
                } else {
                    $out[] = $this->renderdatarow($row, $tpl, $rowkey, $outerdata, $rowscount, $iteration);
                }

                $iteration++;
            }
        }
        $output = implode($this->bloxconfig['outputSeparator'], $out);
        return $output;
    }

    //////////////////////////////////////////////////
    //renderdatarow and custom-innerrows (bloxouterTpl)
    /////////////////////////////////////////////////
    function renderdatarow($row, $rowTpl = 'default', $rowkey = '', $outerdata = array(), $rowscount = 0, $iteration = 0)
    {
        global $modx;

        $date = $this->date;
        $tplN = '';
        if (!empty($this->bloxconfig['getRowTplN'])) {
            $tplN = $this->getRowTplN($row, $rowTpl, $iteration);
        }

        $rowTpl = $this->getRowTpl($row, $rowTpl, $iteration);

        $datarowTplData = array();
        $bloxinnerrows = array();
        $bloxinnercounts = array();
        $innerrows = $modx->getOption('innerrows', $row, '');
        unset($row['innerrows']);

        if (is_array($innerrows)) {
            foreach ($innerrows as $key => $innerrow) {
                $innertpl = $this->getTpl($key);
                if (isset($this->templates[$innertpl]) || $innertpl !== '') {
                    $data = $this->renderdatarows($innerrow, $innertpl, $key, $row);
                    $datarowTplData['innerrows'][$key] = $data;
                    $bloxinnerrows[$key] = $data;
                    $bloxinnercounts[$key] = count($innerrow);
                }
            }
        }

        if (count($bloxinnerrows) > 0) {
            $row['innerrows'] = $bloxinnerrows;
            $row['innercounts'] = $bloxinnercounts;
        }

        $datarowTplData['parent'] = $outerdata;
        $datarowTplData['event'] = $row;
        $datarowTplData['date'] = $date;
        $datarowTplData['row'] = $row;
        $datarowTplData['rowscount'] = $rowscount;
        $datarowTplData['iteration'] = $iteration;

        $datarowTplData['config'] = $this->bloxconfig;
        $datarowTplData['userID'] = $this->bloxconfig['userID'];
        $row['_idx'] = $iteration;
        $row['_alt'] = ($iteration - 1) % 2;
        $row['_first'] = $iteration == 1 ? true : '';
        $row['_last'] = $iteration == $rowscount ? true : '';
        $row['blox'] = $datarowTplData;
        $tpl = new bloxChunkie($rowTpl, array('parseLazy' => $this->bloxconfig['parseLazy']));
        $tpl->placeholders = $row;
        $output = $tpl->Render();

        if (!empty($tplN)) {
            $tpl = new bloxChunkie($tplN, array('parseLazy' => $this->bloxconfig['parseLazy']));
            $row['_tpl'] = $output;
            $tpl->placeholders = $row;
            $output = $tpl->Render();
        }

        unset($tpl, $row);

        return $output;
    }

    //////////////////////////////////////////////////
    //renderdatarow and custom-innerrows (bloxouterTpl)
    /////////////////////////////////////////////////
    function renderdatarowFast($row, $rowTpl = 'default', $rowkey = '', $outerdata = array(), $rowscount = 0, $iteration = 0, $keypath = '', $outerpath = '')
    {

        global $modx;

        $template = $this->getRowTpl($row, $rowTpl, $iteration);
        $parser = new bloxChunkie($rowTpl, array('parseLazy' => $this->bloxconfig['parseLazy']));
        $template = $parser->template;

        $searches = array();
        $replaces = array();
        $innerrows = $modx->getOption('innerrows', $row, '');
        $embeds = $modx->getOption('embeds', $row, array());

        $template = $this->replaceEmbeds($embeds, $template);
        unset($row['innerrows'], $row['embeds']);

        if (is_array($innerrows)) {
            foreach ($innerrows as $key => $innerrow) {
                $innertpl = $this->getTpl($key);
                if (isset($this->templates[$innertpl]) || $innertpl !== '') {
                    $data = $this->renderdatarows($innerrow, $innertpl, $key, $row, $keypath);
                    $searches[] = '[[+innerrows.' . $key . ']]';
                    $replaces[] = $data;
                    $this->parser->placeholders[$keypath . 'innercounts.' . $key] = count($innerrow);
                }
            }
        }

        $this->parser->placeholders[$keypath . '_keypath'] = $keypath;
        $this->parser->placeholders[$keypath . '_parentpath'] = $outerpath;
        $this->parser->placeholders[$keypath . '_rowscount'] = $rowscount;
        $this->parser->placeholders[$keypath . '_idx'] = $iteration + 1;
        $this->parser->placeholders[$keypath . '_0idx'] = $iteration;
        $this->parser->placeholders[$keypath . '_alt'] = ($iteration) % 2;
        $this->parser->placeholders[$keypath . '_first'] = ($iteration + 1) == 1 ? true : '';
        $this->parser->placeholders[$keypath . '_last'] = ($iteration + 1) == $rowscount ? true : '';
        $this->parser->placeholders[$keypath . ''];

        //echo $keypath . '<br />';
        //echo $rowkey . ',';
        $output = str_replace($searches, $replaces, $template);

        $output = str_replace($this->bloxconfig['fastParseTag'], '[[+' . $keypath, $output);

        unset($tpl, $row);

        return $output;
    }

    //////////////////////////////////////////////////////
    //Daten-array holen
    //////////////////////////////////////////////////////
    function getdatas($date, $file)
    {
        global $modx;
        $file = $modx->getOption('core_path') . $file;
        $classfile = str_replace('.php', '.class.php', $file);
        $class = $this->bloxconfig['includesclass'];
        if ($date == 'dayisempty') {
            $bloxdatas = array();
        } else {
            if (file_exists($file)) {
                include $file;
            }
            if (file_exists($classfile)) {
                if (!class_exists($class)) {
                    include($classfile);
                }

                $gd = new $class($this);
                $bloxdatas = $gd->getdatas();
            }
        }

        return $bloxdatas;
    }

    function replaceEmbeds($embeds, $template_in)
    {
        if (count($embeds) > 0) {
            foreach ($embeds as $key => $embed) {
                if ($embed) {
                    $embedtpl = $this->getTpl($key);
                    $embedparser = new bloxChunkie($embedtpl, array('parseLazy' => $this->bloxconfig['parseLazy']));
                    $template = $embedparser->template;
                    $template_in = str_replace('[[+embeds.' . $key . ']]', $template, $template_in);
                }
            }
            //any unreplaced embeds inside?
            foreach ($embeds as $key => $embed) {
                if ($embed) {
                    if (strpos($template_in, '[[+embeds.' . $key . ']]') > 0) {
                        $template_in = $this->replaceEmbeds($embeds, $template_in);
                    }
                }
            }
        }
        return $template_in;
    }

    function getTpl($key)
    {
        global $modx;

        $tpl = '';
        if (isset($this->tpls[$key])) {
            $tpl = $this->tpls[$key];
        } else {
            $tplfile = $this->bloxconfig['tplpath'] . $key . "Tpl.html";
            if (file_exists($modx->getOption('core_path') . $tplfile)) {
                $tpl = "@FILE " . $tplfile;
            }
        }
        return $tpl;
    }

    function getRowTpl($row, $rowTpl, $iteration)
    {
        global $modx;

        if (isset($row['tpl'])) {
            $tplfilename1 = $this->bloxconfig['tplpath'] . $row['tpl'];
            $tplfilename2 = $this->bloxconfig['tplpath'] . $row['tpl'] . 'Tpl.html';
            if ($row['tpl'] !== '') {
                if (file_exists($modx->getOption('core_path') . $tplfilename1)) {
                    $rowTpl = "@FILE " . $tplfilename1;
                } elseif (file_exists($modx->getOption('core_path') . $tplfilename2)) {
                    $rowTpl = "@FILE " . $tplfilename2;
                } else {
                    $rowTpl = $row['tpl'];
                }
            }

            if (substr($rowTpl, 0, 7) == '@FIELD:') {
                $rowTpl = ($row[substr($rowTpl, 7)]);
            }
        }
        return $rowTpl;
    }

    function getRowTplN($row, $rowTpl, $iteration)
    {
        global $modx;
        $tplN = '';
        $iteration++;
        if ($iteration > 1) {
            $divisors = $this->getDivisors($iteration);
            if (!empty($divisors)) {
                foreach ($divisors as $divisor) {
                    $tplnth = str_replace('@FILE ', '', $rowTpl);
                    $tplnth = str_replace('.html', '_n' . $divisor . '.html', $tplnth);
                    /*
                    echo $tplnth;
                    echo '<br />';
                    */
                    if (file_exists($modx->getOption('core_path') . $tplnth)) {
                        $tplN = "@FILE " . $tplnth;
                        break;
                    }
                }
            }
        }

        return $tplN;
    }

    public function getDivisors($integer)
    {
        $divisors = array();
        for ($i = $integer; $i > 1; $i--) {
            if (($integer % $i) === 0) {
                $divisors[] = $i;
            }
        }
        return $divisors;
    }

}
