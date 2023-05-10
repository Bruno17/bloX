<?php

/**
 * Name: bloxChunkie
 * Original name: Chunkie
 * Version: 2.0
 *
 * Author: Armand "bS" Pondman <apondman@zerobarrier.nl>
 * Date: Oct 8, 2006
 *
 * Modified and documented for Revolution by Thomas Jakobi <thomas.jakobi@partout.info>
 * Date: Mar 11, 2013
 */

if (!class_exists('bloxChunkie')) {

    class bloxChunkie
    {

        /**
         * The name of a MODX chunk (could be prefixed by @FILE, @INLINE or
         * @CHUNK). Chunknames starting with '@FILE ' are loading a chunk from
         * the filesystem (prefixed by $basepath). Chunknames starting with
         * '@INLINE ' contain the template code itself.
         *
         * @var string $template
         * @access public
         */
        public $template;

        /**
         * The basepath @FILE is prefixed with.
         * @var string $basepath
         * @access private
         */
        private $basepath;

        /**
         * A collection of all placeholders.
         * @var array $placeholders
         * @access private
         */
        public $placeholders;

        /**
         * The current depth of the placeholder keypath.
         * @var array $depth
         * @access public
         */
        private $depth;

        /**
         * The maximum depth of the placeholder keypath.
         * @var int $maxdepth
         * @access public
         */
        public $maxdepth;

        /**
         * migxfeChunkie constructor
         *
         * @param string $template Name of MODX chunk
         * @param array $options Chunk options
         */
        public function bloxChunkie($template = '', $options = array())
        {
            global $modx;
            $this->options = $options;
            $basepath = $modx->getOption('basepath', $this->options, '');
            $addCore = $modx->getOption('addCore', $this->options, true);
            $this->basepath = ($basepath == '' && $addCore) ? $modx->getOption('core_path') : $basepath;
            $this->template = $this->getTemplate($template);
            $this->depth = 0;
            $this->maxdepth = 10;
            $this->replaceonlyfields = array();

        }

        /**
         * Set the basepath @FILE is prefixed with.
         *
         * @access public
         * @param string $basepath The basepath @FILE is prefixed with.
         */
        public function setBasepath($basepath)
        {
            $this->basepath = $basepath;
        }

        /**
         * Fill placeholder array with values. If $value contains a nested
         * array the key of the subarray is prefixed to the placeholder key
         * separated by dot sign.
         *
         * @access public
         * @param string $value The value(s) the placeholder array is filled
         * with. If $value contains an array, all elements of the array are
         * filled into the placeholder array using key/value. If one array
         * element contains a subarray the function will be called recursive
         * prefixing $keypath with the key of the subarray itself.
         * @param string $key The key $value will get in the placeholder array
         * if it is not an array, otherwise $key will be used as $keypath
         * @param string $keypath The string separated by dot sign $key will
         * be prefixed with
         */
        public function createVars($value = '', $key = '', $keypath = '', $depth = 0)
        {
            $depth++;

            if ($depth > $this->maxdepth) {
                echo 'max' . $depth;
                return;
            }

            $keypath = !empty($keypath) ? $keypath . "." . $key : $key;

            if (is_array($value)) {

                foreach ($value as $subkey => $subval) {
                    $this->createVars($subval, $subkey, $keypath, $depth);
                }
            } else {

                $this->placeholders[$keypath] = $value;
            }
        }

        /**
         * Add one value to the placeholder array with its key.
         *
         * @access public
         * @param string $key The key for the placeholder added
         * @param string $value The value for the placeholder added
         */
        public function addVar($key, $value)
        {
            $this->createVars($value, $key);
        }

        public function setPlaceholder($key, $value)
        {
            $this->createVars($value, $key);
        }

        public function getPlaceholders()
        {
            return $this->placeholders;
        }

        /**
         * Render the current template with the current placeholders.
         *
         * @access public
         * @return string
         */
        public function render($parseLazy = false)
        {
            global $modx;
            $template = $this->template;
            foreach ($this->replaceonlyfields as $field) {
                $field;
                $template = str_replace('[[+' . $field . ']]', '[[##' . $field . ']]', $template);
            }
            $chunk = $modx->newObject('modChunk');
            $chunk->setCacheable(false);
            $template = $chunk->process($this->placeholders, $template);
            unset($chunk);
            foreach ($this->replaceonlyfields as $field) {
                $template = str_replace('[[##' . $field . ']]', $this->placeholders[$field], $template);
            }
            if ($parseLazy) {
                $template = str_replace(array('##!'), array('[[!'), $template);
            }
            return $template;
        }

        /**
         * Set some placeholders as not to be processed
         * @param string|array $f
         */
        public function setReplaceonlyfields($f = '')
        {
            if (!is_array($f)) {
                $f = !empty($f) ? explode(',', $f) : array();
            }
            $this->replaceonlyfields = $f;
        }

        /**
         * Get a template chunk. All chunks retrieved by this function are
         * cached in $modx->chunkieCache for later reusage
         *
         * @access public
         * @param string $tpl The name of a MODX chunk (could be prefixed by
         * @FILE, @INLINE or @CHUNK). Chunknames starting with '@FILE ' are
         * loading a chunk from the filesystem (prefixed by $basepath).
         * Chunknames starting with '@INLINE ' contain the template code itself.
         * @return string
         */
        public function getTemplate($tpl)
        {
            global $modx;

            $template = "";

            if (substr($tpl, 0, 6) == "@FILE ") {
                $filename = substr($tpl, 6);
                if (!isset($modx->chunkieCache['@FILE'])) {
                    $modx->chunkieCache['@FILE'] = array();
                }
                if (!array_key_exists($filename, $modx->chunkieCache['@FILE'])) {
                    if (file_exists($this->basepath . $filename)) {
                        $template = file_get_contents($this->basepath . $filename);
                    }
                    $modx->chunkieCache['@FILE'][$filename] = $template;
                } else {
                    $template = $modx->chunkieCache['@FILE'][$filename];
                }
            } elseif (substr($tpl, 0, 8) == "@INLINE ") {
                $template = substr($tpl, 8);
            } else {
                if (substr($tpl, 0, 7) == "@CHUNK ") {
                    $chunkname = substr($tpl, 7);
                } else {
                    $chunkname = $tpl;
                }
                if (!isset($modx->chunkieCache['@CHUNK'])) {
                    $modx->chunkieCache['@CHUNK'] = array();
                }
                if (!array_key_exists($chunkname, $modx->chunkieCache['@CHUNK'])) {
                    $chunk = $modx->getObject('modChunk', array('name' => $chunkname));
                    if ($chunk) {
                        $modx->chunkieCache['@CHUNK'][$chunkname] = $chunk->getContent();
                    } else {
                        $modx->chunkieCache['@CHUNK'][$chunkname] = false;
                    }
                }
                $template = $modx->chunkieCache['@CHUNK'][$chunkname];
            }

            if ($this->options['parseLazy']) {
                $template = str_replace('[[!', '##!', $template);
            }
            return $template;
        }

        /**
         * Change the template for rendering.
         *
         * @access public
         * @param string $template The new template string for rendering.
         */
        public function setTemplate($template)
        {
            $this->template = $template;
        }

    }

}

