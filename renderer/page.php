<?php
/**
 * ODT Plugin: Exports to ODT
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author Andreas Gohr <andi@splitbrain.org>
 * @author Aurelien Bompard <aurelien@bompard.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

require_once DOKU_PLUGIN . 'odt/helper/cssimport.php';
require_once DOKU_PLUGIN . 'odt/ODT/ODTDefaultStyles.php';
require_once DOKU_PLUGIN . 'odt/ODT/ODTmeta.php';
require_once DOKU_PLUGIN . 'odt/ODT/page.php';

// Supported document handlers.
require_once DOKU_PLUGIN . 'odt/ODT/docHandler.php';
require_once DOKU_PLUGIN . 'odt/ODT/scratchDH.php';
require_once DOKU_PLUGIN . 'odt/ODT/ODTTemplateDH.php';

/**
 * The Renderer
 */
class renderer_plugin_odt_page extends Doku_Renderer {
    /** @var array store the table of contents */
    protected $toc = array();
    /** @var array store the bookmarks */
    protected $bookmarks = array();
    /** @var array store the table of contents */
    public $toc_settings = null;
    /** @var export mode (scratch or ODT template) */
    protected $mode = 'scratch';
    /** @var docHandler */
    protected $docHandler = null;
    /** @var helper_plugin_odt_stylefactory */
    protected $factory = null;
    /** @var helper_plugin_odt_cssimport */
    protected $import = null;
    /** @var helper_plugin_odt_units */
    protected $units = null;
    /** @var ODTStyleSet */
    protected $styleset = null;
    /** @var ODTMeta */
    protected $meta;
    /** @var string temporary storage of xml-content */
    protected $store = '';
    /** @var array */
    protected $footnotes = array();
    protected $headers = array();
    /** @var helper_plugin_odt_config */
    protected $config = null;
    public $fields = array(); // set by Fields Plugin
    protected $in_list = null;
    protected $list_interrupted = null;
    protected $in_list_item = false;
    protected $in_paragraph = false;
    protected $in_div_as_frame = 0;
    protected $highlight_style_num = 1;
    protected $temp_table_column_styles = array ();
    protected $temp_table_style = NULL;
    protected $temp_in_header = false;
    protected $temp_autocols = false;
    protected $temp_maxcols = 0;
    protected $temp_column = 0;
    protected $temp_content = NULL;
    protected $temp_cols = NULL;
    protected $quote_depth = 0;
    protected $quote_pos = 0;
    protected $div_z_index = 0;
    /** @var Current pageFormat */
    protected $page = null;
    /** @var Array of used page styles. Will stay empty if only A4-portrait is used */
    protected $page_styles = array ();
    /** @var Array of paragraph style names that prevent an empty paragraph from being deleted */
    protected $preventDeletetionStyles = array ();
    /** @var refIDCount */
    protected $refIDCount = 0;
    /** @var pageBookmark */
    protected $pageBookmark = NULL;
    /** @var pagebreak */
    protected $pagebreak = false;
    /** @var changePageFormat */
    protected $changePageFormat = NULL;

    /**
     * Automatic styles. Will always be added to content.xml and styles.xml.
     * Initalized by function initStyles.
     * @var array
     */
    public $autostyles = array();
    /**
     * Regular styles. May not be present if in template mode, in which case they will be added to styles.xml
     * Initalized by function initStyles.
     * @var array
     */
    protected $styles = array();
    /** @var string */
    protected $css;
    /** @var  string buffer for extracted template */
    protected $temp_dir;
    /** @var  int counter for styles */
    protected $style_count;

    // Only for debugging
    //var $trace_dump;

    /**
     * Constructor. Loads helper plugins.
     */
    public function __construct() {
        // Set up empty array with known config parameters
        $this->config = plugin_load('helper', 'odt_config');

        $this->factory = plugin_load('helper', 'odt_stylefactory');

        $this->meta = new ODTMeta();
    }

    /**
     * Set a config parameter from extern.
     */
    public function setConfigParam($name, $value) {
        $this->config->setParam($name, $value);
    }

    /**
     * Is the $string specified the name of a ODT plugin config parameter?
     *
     * @return bool Is it a config parameter?
     */
    public function isConfigParam($string) {
        return $this->config->isParam($string);
    }

    /**
     * Return version info
     */
    function getInfo(){
        return confToHash(dirname(__FILE__).'/plugin.info.txt');
    }

    /**
     * Returns the format produced by this renderer.
     */
    function getFormat(){
        return "odt";
    }

    /**
     * Do not make multiple instances of this class
     */
    function isSingleton(){
        return true;
    }

    /**
     * Load and imports CSS.
     */
    protected function load_css() {
        /** @var helper_plugin_odt_dwcssloader $loader */
        $loader = plugin_load('helper', 'odt_dwcssloader');
        if ( $loader != NULL ) {
            $this->css = $loader->load
                ('odt', 'odt', $this->config->getParam('css_template'), $this->config->getParam('usestyles'));
        }

        $this->import = plugin_load('helper', 'odt_cssimport');
        if ( $this->import != NULL ) {
            $this->import->importFromString ($this->css);
        }

        // Call adjustLengthValues to make our callback function being called for every
        // length value imported. This gives us the chance to convert it once from
        // pixel to points.
        $this->import->adjustLengthValues (array($this, 'adjustLengthCallback'));
    }

    /**
     * Load and configure units helper.
     */
    protected function setupUnits()
    {
        // Load helper class for unit conversion.
        $this->units = plugin_load('helper', 'odt_units');
        $this->units->setPixelPerEm(14);
        $this->units->setTwipsPerPixelX($this->config->getParam ('twips_per_pixel_x'));
        $this->units->setTwipsPerPixelY($this->config->getParam ('twips_per_pixel_y'));
    }

    /**
     * Initialize the rendering
     */
    function document_start() {
        global $ID;

        // Reset TOC.
        $this->toc = array();

        // First, get export mode.
        $warning = '';
        $this->mode = $this->config->load($warning);
        //$this->config->setParam ($, $this->getConf('format'));
        //FIXME: output warning to document, like:
/*                // template chosen but not found : warn the user and use the default template
                $warning = '<text:p text:style-name="'.$this->styleset->getStyleName('body').'"><text:span text:style-name="'.$this->styleset->getStyleName('strong').'">'
                             .$this->_xmlEntities( sprintf($this->getLang('tpl_not_found'),$this->config ['odt_template'],$this->getConf("tpl_dir")) )
                             .'</text:span></text:p>'.$this->doc;*/

        // Load and import CSS files, setup Units
        $this->load_css();
        $this->setupUnits();

        switch($this->mode) {
            case 'ODT template':
                // Document based on ODT template.
                $this->docHandler = new ODTTemplateDH ();
                $this->docHandler->setTemplate($this->config->getParam ('odt_template'));
                $this->docHandler->setDirectory($this->config->getParam ('tpl_dir'));
                break;

            default:
                // Document from scratch.
                $this->docHandler = new scratchDH ();
                break;
        }

        // Setup page format.
        $this->page = new pageFormat();
        $this->setPageFormat($this->config->getParam ('format'),
                             $this->config->getParam ('orientation'),
                             $this->config->getParam ('margin_top'),
                             $this->config->getParam ('margin_right'),
                             $this->config->getParam ('margin_bottom'),
                             $this->config->getParam ('margin_left'));
        //$this->page->setFormat('A4', 'portrait');



        // Load Styleset.
        $this->styleset = new ODTDefaultStyles();
        $this->styleset->import();
        $this->autostyles = $this->styleset->getAutoStyles($this->page);
        $this->styles = $this->styleset->getStyles();

        // Set title in meta info.
        $this->meta->setTitle($ID); //FIXME article title != book title  SOLUTION: overwrite at the end for book

        // If older or equal to 2007-06-26, we need to disable caching
        $dw_version = preg_replace('/[^\d]/', '', getversion());  //FIXME DEPRECATED
        if (version_compare($dw_version, "20070626", "<=")) {
            $this->info["cache"] = false;
        }


        //$headers = array('Content-Type'=>'text/plain'); p_set_metadata($ID,array('format' => array('odt' => $headers) )); return ; // DEBUG
        // send the content type header, new method after 2007-06-26 (handles caching)
        $output_filename = str_replace(':','-',$ID).".odt";
        if (version_compare($dw_version, "20070626")) {
            // store the content type headers in metadata
            $headers = array(
                'Content-Type' => 'application/vnd.oasis.opendocument.text',
                'Content-Disposition' => 'attachment; filename="'.$output_filename.'";',
            );
            p_set_metadata($ID,array('format' => array('odt_page' => $headers) ));
        } else { // older method FIXME DEPRECATED
            header('Content-Type: application/vnd.oasis.opendocument.text');
            header('Content-Disposition: attachment; filename="'.$output_filename.'";');
        }

        $this->set_page_bookmark($ID);
    }

    /**
     * Closes the document
     */
    function document_end(){
        //$this->doc .= $this->_odtAutoStyles(); return; // DEBUG
        // DEBUG: The following puts out the loaded raw CSS code
        //$this->p_open();
        // This line outputs the raw CSS code
        //$test = 'CSS: '.$this->css;
        // The next two lines output the parsed CSS rules with linebreaks
        //$test = $this->import->rulesToString();
        //$this->doc .= preg_replace ('/\n/', '<text:line-break/>', $test);
        //$this->linebreak();
        //$this->doc .= 'Tracedump: '.$this->trace_dump;
        //$this->p_close();

        // Insert TOC (if required)
        $this->insert_TOC();

        // Replace local link placeholders
        $this->insert_locallinks();

        // Build the document
        $this->finalize_ODTfile();

        // Refresh certain config parameters e.g. 'disable_links'
        $this->config->refresh();
    }

    /**
     * This function sets the page format.
     * The format, orientation and page margins can be changed.
     * See function queryFormat() in ODT/page.php for supported formats.
     *
     * @param string  $format         e.g. 'A4', 'A3'
     * @param string  $orientation    e.g. 'portrait' or 'landscape'
     * @param numeric $margin_top     Top-Margin in cm, default 2
     * @param numeric $margin_right   Right-Margin in cm, default 2
     * @param numeric $margin_bottom  Bottom-Margin in cm, default 2
     * @param numeric $margin_left    Left-Margin in cm, default 2
     */
    public function setPageFormat ($format=NULL, $orientation=NULL, $margin_top=NULL, $margin_right=NULL, $margin_bottom=NULL, $margin_left=NULL) {
        $data = array ();

        // Fill missing values with current settings
        if ( empty($format) ) {
            $format = $this->page->getFormat();
        }
        if ( empty($orientation) ) {
            $orientation = $this->page->getOrientation();
        }
        if ( empty($margin_top) ) {
            $margin_top = $this->page->getMarginTop();
        }
        if ( empty($margin_right) ) {
            $margin_right = $this->page->getMarginRight();
        }
        if ( empty($margin_bottom) ) {
            $margin_bottom = $this->page->getMarginBottom();
        }
        if ( empty($margin_left) ) {
            $margin_left = $this->page->getMarginLeft();
        }

        // Adjust given parameters, query resulting format data and get format-string
        $this->page->queryFormat ($data, $format, $orientation, $margin_top, $margin_right, $margin_bottom, $margin_left);
        $format_string = $this->page->formatToString ($data['format'], $data['orientation'], $data['margin-top'], $data['margin-right'], $data['margin-bottom'], $data['margin-left']);

        if ( $format_string == $this->page->toString () ) {
            // Current page already uses this format, no need to do anything...
            return;
        }

        // Set marker and save data for pending change format.
        // The format change istelf will be done on the next call to p_open or header()
        // to prevent empty lines after the format change.
        $this->changePageFormat = $data;

        // Close paragraph if open
        $this->p_close();
    }

    /**
     * This function creates a style for changin the page format if required.
     * It returns NULL if no page format change is pending or if the current
     * page format is equal to the required page format.
     *
     * @param string  $parent Parent style name.
     * @return string Name of the style to be used for changing page format
     */
    protected function doPageFormatChange ($parent = NULL) {
        if ( $this->changePageFormat == NULL ) {
            // Error.
            return NULL;
        }
        $data = $this->changePageFormat;
        $this->changePageFormat = NULL;

        if ( empty($parent) ) {
            $parent = 'Standard';
        }

        // Create page layout style
        $format_string = $this->page->formatToString ($data['format'], $data['orientation'], $data['margin-top'], $data['margin-right'], $data['margin-bottom'], $data['margin-left']);
        $properties ['style-name']    = 'Style-Page-'.$format_string;
        $properties ['width']         = $data ['width'];
        $properties ['height']        = $data ['height'];
        $properties ['margin-top']    = $data ['margin-top'];
        $properties ['margin-bottom'] = $data ['margin-bottom'];
        $properties ['margin-left']   = $data ['margin-left'];
        $properties ['margin-right']  = $data ['margin-right'];
        $style_name = $this->factory->createPageLayoutStyle($style_content, $properties);

        // Save style data in page style array, in common styles and set current page format
        $master_page_style_name = $format_string;
        $this->page_styles [$master_page_style_name] = $style_name;
        $this->autostyles [$style_name] = $style_content;
        $this->page->setFormat($data ['format'], $data ['orientation'], $data['margin-top'], $data['margin-right'], $data['margin-bottom'], $data['margin-left']);

        // Create paragraph style.
        $properties = array();
        $properties ['style-name']       = 'Style-'.$format_string;
        $properties ['master-page-name'] = $master_page_style_name;
        $properties ['style-parent']     = $parent;
        $properties ['page-number']      = 'auto';
        $style_name = $this->factory->createParagraphStyle($style_content, $properties);
        $this->autostyles [$style_name] = $style_content;

        // Save paragraph style name in 'Do not delete array'!
        $this->preventDeletetionStyles [] = $style_name;

        return $style_name;
    }

    /**
     * This function deletes the useless elements. Right now, these are empty paragraphs
     * or paragraphs that only include whitespace.
     *
     * IMPORTANT:
     * Paragraphs can be used for pagebreaks/changing page format.
     * Such paragraphs may not be deleted!
     */
    protected function deleteUselessElements() {
        $length_open = strlen ('<text:p>');
        $length_close = strlen ('</text:p>');
        $max = strlen ($this->doc);
        $pos = 0;

        while ($pos < $max) {
            $start_open = strpos ($this->doc, '<text:p', $pos);
            if ( $start_open === false ) {
                break;
            }
            $start_close = strpos ($this->doc, '>', $start_open + $length_open);
            if ( $start_close === false ) {
                break;
            }
            $end = strpos ($this->doc, '</text:p>', $start_close + 1);
            if ( $end === false ) {
                break;
            }

            $deleted = false;
            $length = $end - $start_open + $length_close;
            $content = substr ($this->doc, $start_close + 1, $end - ($start_close + 1));

            if ( empty($content) || ctype_space ($content) ) {
                // Paragraph is empty or consists of whitespace only. Check style name.
                $style_start = strpos ($this->doc, '"', $start_open);
                if ( $style_start === false ) {
                    // No '"' found??? Ignore this paragraph.
                    break;
                }
                $style_end = strpos ($this->doc, '"', $style_start+1);
                if ( $style_end === false ) {
                    // No '"' found??? Ignore this paragraph.
                    break;
                }
                $style_name = substr ($this->doc, $style_start+1, $style_end - ($style_start+1));

                // Only delete empty paragraph if not listed in 'Do not delete' array!
                if ( !in_array($style_name, $this->preventDeletetionStyles) )
                {
                    $this->doc = substr_replace($this->doc, '', $start_open, $length);

                    $deleted = true;
                    $max -= $length;
                    $pos = $start_open;
                }
            }

            if ( $deleted == false ) {
                $pos = $start_open + $length;
            }
        }
    }

    /**
     * Completes the ODT file
     */
    public function finalize_ODTfile() {
        // Delete paragraphs which only contain whitespace (but keep pagebreaks!)
        $this->deleteUselessElements();

        // Build the document
        $this->docHandler->build($this->doc,
                                 $this->_odtAutoStyles(),
                                 $this->styles,
                                 $this->meta->getContent(),
                                 $this->_odtUserFields(),
                                 $this->styleset,
                                 $this->page_styles);

        // Assign document
        $this->doc = $this->docHandler->get();
    }

    /**
     * Simple setter to enable creating links
     */
    function enable_links() {
        $this->setParam ('disable_links', false);
    }

    /**
     * Simple setter to disable creating links
     */
    function disable_links() {
        $this->setParam ('disable_links', true);
    }

    /**
     * This function does not really render the TOC but inserts a placeholder.
     * See also insert_TOC().
     *
     * @return string
     */
    function render_TOC() {
        $this->p_close();
        $this->doc .= '<text:table-of-content/>';
        return '';
    }

    /**
     * This function builds the actual TOC and replaces the placeholder with it.
     * It is called in document_end() after all headings have been added to the TOC, see toc_additem().
     * The page numbers are just a counter. Update the TOC e.g. in LibreOffice to get the real page numbers!
     *
     * The TOC is inserted by the syntax tag '{{odt>toc:setting=value;}};'.
     * The following settings are supported:
     * - Title e.g. '{{odt>toc:title=Example;}}'.
     *   Default is 'Table of Contents' (for english, see language files for other languages default value).
     * - Leader sign, e.g. '{{odt>toc:leader-sign=.;}}'.
     *   Default is '.'.
     * - Indents (in cm), e.g. '{{odt>toc:indents=indents=0,0.5,1,1.5,2,2.5,3;}};'.
     *   Default is 0.5 cm indent more per level.
     * - Maximum outline/TOC level, e.g. '{{odt>toc:maxtoclevel=5;}}'.
     *   Default is taken from DokuWiki config setting 'maxtoclevel'.
     * - Insert pagebreak after TOC, e.g. '{{odt>toc:pagebreak=1;}}'.
     *   Default is '1', means insert pagebreak after TOC.
     * - Set style per outline/TOC level, e.g. '{{odt>toc:styleL2="color:red;font-weight:900;";}}'.
     *   Default is 'color:black'.
     *
     * It is allowed to use defaults for all settings by using '{{odt>toc}}'.
     * Multiple settings can be combined, e.g. '{{odt>toc:leader-sign=.;indents=0,0.5,1,1.5,2,2.5,3;}}'.
     */
    protected function insert_TOC() {
        $matches = array();
        $stylesL = array();
        $stylesLNames = array();

        // It seems to be not supported in ODT to have a different start
        // outline level than 1.
        $max_outline_level = $this->config->getParam('toc_maxlevel');
        if ( preg_match('/maxlevel=[^;]+;/', $this->toc_settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 12);
            $temp = trim ($temp, ';');
            $max_outline_level = $temp;
        }

        // Determine title, default is 'Table of Contents'.
        // Syntax for 'Test' as title would be "title=test;".
        $title = $this->getLang('toc_title');
        if ( preg_match('/title=[^;]+;/', $this->toc_settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 6);
            $temp = trim ($temp, ';');
            $title = $temp;
        }

        // Determine leader-sign, default is '.'.
        // Syntax for '.' as leader-sign would be "leader_sign=.;".
        $leader_sign = $this->config->getParam('toc_leader_sign');
        if ( preg_match('/leader_sign=[^;]+;/', $this->toc_settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 12);
            $temp = trim ($temp, ';');
            $leader_sign = $temp [0];
        }

        // Determine indents, default is '0.5' (cm) per level.
        // Syntax for a indent of '0.5' for 5 levels would be "indents=0,0.5,1,1.5,2;".
        // The values are absolute for each level, not relative to the higher level.
        $indents = explode (',', $this->config->getParam('toc_indents'));
        if ( preg_match('/indents=[^;]+;/', $this->toc_settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 8);
            $temp = trim ($temp, ';');
            $indents = explode (',', $temp);
        }

        // Determine pagebreak, default is on '1'.
        // Syntax for pagebreak off would be "pagebreak=0;".
        $toc_pagebreak = $this->config->getParam('toc_pagebreak');
        if ( preg_match('/pagebreak=[^;]+;/', $this->toc_settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 10);
            $temp = trim ($temp, ';');
            $toc_pagebreak = false;            
            if ( $temp == '1' ) {
                $toc_pagebreak = true;
            } else if ( strcasecmp($temp, 'true') == 0 ) {
                $toc_pagebreak = true;
            }
        }

        // Determine text styles per level.
        // Syntax for a style level 1 is "styleL1="color:black;"".
        // The default style is just 'color:black;'.
        for ( $count = 0 ; $count < $max_outline_level ; $count++ ) {
            $stylesL [$count + 1] = $this->config->getParam('toc_style');
            if ( preg_match('/styleL'.($count + 1).'="[^"]+";/', $this->toc_settings, $matches) === 1 ) {
                $quote = strpos ($matches [0], '"');
                $temp = substr ($matches [0], $quote+1);
                $temp = trim ($temp, '";');
                $stylesL [$count + 1] = $temp.';';
            }
        }

        // Create paragraph styles
        $p_styles = array();
        $indent = 0;
        for ( $count = 0 ; $count < $max_outline_level ; $count++ )
        {
            $indent = $indents [$count];
            $properties = array();
            $properties ['style-parent'] = 'Index';
            $properties ['style-class'] = 'index';
            $properties ['style-position'] = 17 - $indent .'cm';
            $properties ['style-type'] = 'right';
            $properties ['style-leader-style'] = 'dotted';
            $properties ['style-leader-text'] = $leader_sign;
            $properties ['margin-left'] = $indent.'cm';
            $properties ['margin-right'] = '0cm';
            $properties ['text-indent'] = '0cm';
            $style_content = '';
            $style_name = $this->factory->createParagraphStyle($style_content, $properties);

            // Add paragraph style to styles array (= common styles).
            // (It MUST be added to styles NOT to autostyles. Otherwise LibreOffice will
            //  overwrite/change the style on updating the TOC!!!)
            $this->styles[$style_name] = $style_content;
            $p_styles [$count+1] = $style_name;
        }

        // Create text style for TOC text.
        // (this MUST be a text style (not paragraph!) and MUST be placed in styles (not autostyles) to work!)
        for ( $count = 0 ; $count < $max_outline_level ; $count++ ) {
            $properties = array();
            $this->_processCSSStyle ($properties, $stylesL [$count+1]);
            $style_content = '';
            $stylesLNames [$count+1] = $this->factory->createTextStyle($style_content, $properties);
            $this->styles[$stylesLNames [$count+1]] = $style_content;
        }

        // Generate ODT toc tag and content
        $toc  = '<text:table-of-content text:style-name="Standard" text:protected="true" text:name="Table of Contents">';
        $toc .= '<text:table-of-content-source text:outline-level="'.$max_outline_level.'">';
        $toc .= '<text:index-title-template text:style-name="'.$this->styleset->getStyleName('heading1').'">'.$title.'</text:index-title-template>';

        // Create TOC templates per outline level.
        // The styles listed here need to be the same as later used for the headers.
        // Otherwise the style of the TOC entries/headers will change after an update.
        for ( $count = 0 ; $count < $max_outline_level ; $count++ )
        {
            $level = $count + 1;
            $toc .= '<text:table-of-content-entry-template text:outline-level="'.$level.'" text:style-name="'.$p_styles [$level].'">';
            $toc .= '<text:index-entry-link-start text:style-name="'.$stylesLNames [$level].'"/>';
            $toc .= '<text:index-entry-chapter/>';
            $toc .= '<text:index-entry-text/>';
            $toc .= '<text:index-entry-tab-stop style:type="right" style:leader-char="'.$leader_sign.'"/>';
            $toc .= '<text:index-entry-page-number/>';
            $toc .= '<text:index-entry-link-end/>';
            $toc .= '</text:table-of-content-entry-template>';
        }

        $toc .= '</text:table-of-content-source>';
        $toc .= '<text:index-body>';
        $toc .= '<text:index-title text:style-name="Standard" text:name="Table of Contents_Head">';
        $toc .= '<text:p text:style-name="'.$this->styleset->getStyleName('heading1').'">'.$title.'</text:p>';
        $toc .= '</text:index-title>';

        // Add headers to TOC.
        $page = 0;
        foreach ($this->toc as $item) {
            $params = explode (',', $item);

            // Only add the heading to the TOC if its <= $max_outline_level
            if ( $params [3] <= $max_outline_level ) {
                $level = $params [3];
                $toc .= '<text:p text:style-name="'.$p_styles [$level].'">';
                $toc .= '<text:a xlink:type="simple" xlink:href="#'.$params [0].'" text:style-name="'.$stylesLNames [$level].'" text:visited-style-name="'.$stylesLNames [$level].'">';
                $toc .= $params [2];
                $toc .= '<text:tab/>';
                $page++;
                $toc .= $page;
                $toc .= '</text:a>';
                $toc .= '</text:p>';
            }
        }

        $toc .= '</text:index-body>';
        $toc .= '</text:table-of-content>';

        // Add a pagebreak if required.
        $toc_pagebreak = $this->config->getParam('toc_pagebreak');
        if ( $toc_pagebreak ) {
            $style_name = $this->createPagebreakStyle(NULL, false);
            $toc .= '<text:p text:style-name="'.$style_name.'"/>';
        }

        // Only for debugging
        //foreach ($this->toc as $item) {
        //    $params = explode (',', $item);
        //    $toc .= '<text:p>'.$params [0].'€'.$params [1].'€'.$params [2].'€'.$params [3].'</text:p>';
        //}

        // Replace placeholder with TOC content.
        $this->doc = str_replace ('<text:table-of-content/>', $toc, $this->doc);
    }

    /**
     * Creates a reference ID for the TOC
     *
     * @param string $title The headline/item title
     * @return string
     *
     * @author LarsDW223
     */
    protected function _buildTOCReferenceID($title) {
        $title = str_replace(':','',cleanID($title));
        $title = ltrim($title,'0123456789._-');
        if(empty($title)) {
            $title='NoTitle';
        }

        $this->refIDCount++;
        // The reference ID needs to start with '__RefHeading___'.
        // Otherwise LibreOffice will display $ref instead of the heading
        // name when moving the mouse over the link in the TOC.
        $ref = '__RefHeading___'.$title.'_'.$this->refIDCount;
        return $ref;
    }

    /**
     * Add an item to the TOC
     *
     * @param string $refID    the reference ID
     * @param string $text     the text to display
     * @param int    $level    the nesting level
     */
    function toc_additem($refID, $hid, $text, $level) {
        $item = $refID.','.$hid.','.$text.','. $level;
        $this->toc[] = $item;
    }

    /**
     * Return total page width in centimeters
     * (margins are included)
     *
     * @author LarsDW223
     */
    function _getPageWidth(){
        return $this->page->getWidth();
    }

    /**
     * Return total page height in centimeters
     * (margins are included)
     *
     * @author LarsDW223
     */
    function _getPageHeight(){
        return $this->page->getHeight();
    }

    /**
     * Return left margin in centimeters
     *
     * @author LarsDW223
     */
    function _getLeftMargin(){
        return $this->page->getMarginLeft();
    }

    /**
     * Return right margin in centimeters
     *
     * @author LarsDW223
     */
    function _getRightMargin(){
        return $this->page->getMarginRight();
    }

    /**
     * Return top margin in centimeters
     *
     * @author LarsDW223
     */
    function _getTopMargin(){
        return $this->page->getMarginTop();
    }

    /**
     * Return bottom margin in centimeters
     *
     * @author LarsDW223
     */
    function _getBottomMargin(){
        return $this->page->getMarginBottom();
    }

    /**
     * Return width percentage value if margins are taken into account.
     * Usually "100%" means 21cm in case of A4 format.
     * But usually you like to take care of margins. This function
     * adjusts the percentage to the value which should be used for margins.
     * So 100% == 21cm e.g. becomes 80.9% == 17cm (assuming a margin of 2 cm on both sides).
     *
     * @author LarsDW223
     *
     * @param int|string $percentage
     * @return int|string
     */
    function _getRelWidthMindMargins ($percentage = '100'){
        return $this->page->getRelWidthMindMargins($percentage);
    }

    /**
     * Like _getRelWidthMindMargins but returns the absulute width
     * in centimeters.
     *
     * @author LarsDW223
     * @param string|int|float $percentage
     * @return float
     */
    function _getAbsWidthMindMargins ($percentage = '100'){
        return $this->page->getAbsWidthMindMargins($percentage);
    }

    /**
     * Return height percentage value if margins are taken into account.
     * Usually "100%" means 29.7cm in case of A4 format.
     * But usually you like to take care of margins. This function
     * adjusts the percentage to the value which should be used for margins.
     * So 100% == 29.7cm e.g. becomes 86.5% == 25.7cm (assuming a margin of 2 cm on top and bottom).
     *
     * @author LarsDW223
     *
     * @param string|float|int $percentage
     * @return float|string
     */
    function _getRelHeightMindMargins ($percentage = '100'){
        return $this->page->getRelHeightMindMargins($percentage);
    }

    /**
     * Like _getRelHeightMindMargins but returns the absulute width
     * in centimeters.
     *
     * @author LarsDW223
     *
     * @param string|int|float $percentage
     * @return float
     */
    function _getAbsHeightMindMargins ($percentage = '100'){
        return $this->page->getAbsHeightMindMargins($percentage);
    }

    /**
     * @return string
     */
    function _odtAutoStyles() {
        $value = '<office:automatic-styles>';
        foreach ($this->autostyles as $stylename=>$stylexml) {
            $value .= $stylexml;
        }
        $value .= '</office:automatic-styles>';
        return $value;
    }

    /**
     * @return string
     */
    function _odtUserFields() {
        $value = '<text:user-field-decls>';
        foreach ($this->fields as $fname=>$fvalue) {
            $value .= '<text:user-field-decl office:value-type="string" text:name="'.$fname.'" office:string-value="'.$fvalue.'"/>';
        }
        $value .= '</text:user-field-decls>';
        return $value;
    }

    /**
     * Render plain text data
     *
     * @param string $text
     */
    function cdata($text) {
        // Insert pagebreak or page format change if still pending.
        // Attention: NOT if $text is empty. This would lead to empty lines before headings
        //            right after a pagebreak!
        if ( !empty($text) ) {
            if ( ($this->pagebreak || $this->changePageFormat != NULL) && !$this->in_paragraph ) {
                $this->p_open();
            }
        }
        $this->doc .= $this->_xmlEntities($text);
    }

    /**
     * Open a paragraph
     *
     * @param string $style
     */
    function p_open($style=NULL){
        if ( empty($style) ) {
            $style = $this->styleset->getStyleName('body');
        }
        if (!$this->in_paragraph) { // opening a paragraph inside another paragraph is illegal
            $this->in_paragraph = true;
            if ( $this->changePageFormat != NULL ) {
                $page_style = $this->doPageFormatChange($style);
                if ( $page_style != NULL ) {
                    $style = $page_style;
                    // Delete pagebreak, the format change will also introduce a pagebreak.
                    $this->pagebreak = false;
                }
            }
            if ( $this->pagebreak ) {
                $style = $this->createPagebreakStyle ($style);
                $this->pagebreak = false;
            }
            $this->doc .= '<text:p text:style-name="'.$style.'">';
        }

        // Insert page bookmark if requested and not done yet.
        if ( !empty($this->pageBookmark) ) {
            $this->insert_bookmark($this->pageBookmark, false);
            $this->pageBookmark = NULL;
        }
    }

    function p_close(){
        if ($this->in_paragraph) {
            $this->in_paragraph = false;
            $this->doc .= '</text:p>';
        }
    }

    /**
     * Insert a bookmark.
     *
     * @param string $id    ID of the bookmark
     */
    function insert_bookmark($id,$open_paragraph=true){
        if ($open_paragraph) {
            $this->p_open();
        }
        $this->doc .= '<text:bookmark text:name="'.$id.'"/>';
        $this->bookmarks [] = $id;
    }

    /**
     * Set bookmark for the start of the page. This just saves the title temporarily.
     * It is then to be inserted in the first header or paragraph.
     *
     * @param string $id    ID of the bookmark
     */
    function set_page_bookmark($id){
        if ( $this->in_paragraph ) {
            $this->insert_bookmark($id);
        } else {
            $this->pageBookmark = $id;
        }
    }

    /**
     * Render a heading
     *
     * @param string $text  the text to display
     * @param int    $level header level
     * @param int    $pos   byte position in the original source
     */
    function header($text, $level, $pos){
        $this->p_close();
        $hid = $this->_headerToLink($text,true);
        $TOCRef = $this->_buildTOCReferenceID($text);
        $style = $this->styleset->getStyleName('heading'.$level);
        if ( $this->changePageFormat != NULL ) {
            $page_style = $this->doPageFormatChange($style);
            if ( $page_style != NULL ) {
                $style = $page_style;
                // Delete pagebreak, the format change will also introduce a pagebreak.
                $this->pagebreak = false;
            }
        }
        if ( $this->pagebreak ) {
            $style = $this->createPagebreakStyle ($style);
            $this->pagebreak = false;
        }
        $this->doc .= '<text:h text:style-name="'.$style.'" text:outline-level="'.$level.'">';

        // Insert page bookmark if requested and not done yet.
        if ( !empty($this->pageBookmark) ) {
            $this->insert_bookmark($this->pageBookmark, false);
            $this->pageBookmark = NULL;
        }

        $this->doc .= '<text:bookmark-start text:name="'.$TOCRef.'"/>';
        $this->doc .= '<text:bookmark-start text:name="'.$hid.'"/>';
        $this->doc .= $this->_xmlEntities($text);
        $this->doc .= '<text:bookmark-end text:name="'.$TOCRef.'"/>';
        $this->doc .= '<text:bookmark-end text:name="'.$hid.'"/>';
        $this->doc .= '</text:h>';

        // Do not add headings in frames
        if ( $this->in_div_as_frame == 0 ) {
            $this->toc_additem($TOCRef, $hid, $text, $level);
        }
    }

    function hr() {
        $this->p_close();
        $this->doc .= '<text:p text:style-name="'.$this->styleset->getStyleName('horizontal line').'"/>';
    }

    function linebreak() {
        $this->doc .= '<text:line-break/>';
    }

    protected function createPagebreakStyle($parent=NULL,$before=true) {
        $style_name = 'pagebreak';
        $attr = 'fo:break-before';
        if ( !$before ) {
            $style_name .= '_after';
            $attr = 'fo:break-after';
        }
        if ( !empty($parent) ) {
            $style_name .= '_'.$parent;
        }
        if ( empty ($this->autostyles[$style_name]) ) {
            $this->autostyles[$style_name] = '<style:style style:name="'.$style_name.'" style:family="paragraph" style:parent-style-name="'.$parent.'"><style:paragraph-properties '.$attr.'="page"/></style:style>';

            // Save paragraph style name in 'Do not delete array'!
            $this->preventDeletetionStyles [] = $style_name;
        }
        return $style_name;
    }

    function pagebreak() {
        // Only set marker to insert a pagebreak on "next occasion".
        // The pagebreak will then be inserted in the next call to p_open() or header().
        // The style will be a "pagebreak" style with the paragraph or header style as the parent.
        // This prevents extra empty lines after the pagebreak.
        $this->p_close();
        $this->pagebreak = true;
    }

    function strong_open() {
        $this->doc .= '<text:span text:style-name="'.$this->styleset->getStyleName('strong').'">';
    }

    function strong_close() {
        $this->doc .= '</text:span>';
    }

    function emphasis_open() {
        $this->doc .= '<text:span text:style-name="'.$this->styleset->getStyleName('emphasis').'">';
    }

    function emphasis_close() {
        $this->doc .= '</text:span>';
    }

    function underline_open() {
        $this->doc .= '<text:span text:style-name="'.$this->styleset->getStyleName('underline').'">';
    }

    function underline_close() {
        $this->doc .= '</text:span>';
    }

    function monospace_open() {
        $this->doc .= '<text:span text:style-name="'.$this->styleset->getStyleName('monospace').'">';
    }

    function monospace_close() {
        $this->doc .= '</text:span>';
    }

    function subscript_open() {
        $this->doc .= '<text:span text:style-name="'.$this->styleset->getStyleName('sub').'">';
    }

    function subscript_close() {
        $this->doc .= '</text:span>';
    }

    function superscript_open() {
        $this->doc .= '<text:span text:style-name="'.$this->styleset->getStyleName('sup').'">';
    }

    function superscript_close() {
        $this->doc .= '</text:span>';
    }

    function deleted_open() {
        $this->doc .= '<text:span text:style-name="'.$this->styleset->getStyleName('del').'">';
    }

    function deleted_close() {
        $this->doc .= '</text:span>';
    }

    /**
     * Interrupt a list and set variables to continue it later
     * in form of a new list continuing the numbering.
     *
     * This is necessary if a table is part of a list item as in the ODT
     * format a table:table element may not be included or be child of a list item.
     */
    protected function interruptList () {
        if ($this->in_list) {
            $this->listcontent_close();
            $this->listitem_close();

            switch($this->in_list) {
                case 'listu':
                    $this->listu_close();
                    $this->list_interrupted = 'listu';
                    break;
                case 'listo':
                    $this->listo_close();
                    $this->list_interrupted = 'listo';
                    break;
            }
        }
    }

    /**
     * Continue/re-open a list previously closed/interrupted by
     * calling interruptList ().
     */
    protected function continueList () {
        if ($this->list_interrupted != null) {
            switch($this->list_interrupted) {
                case 'listu':
                    $this->listu_open(true);
                    break;
                case 'listo':
                    $this->listo_open(true);
                    break;
            }
            // list_interrupted will be reset on the next call to listitem_open():
            // the parser will still call listcontent_close() and listitem_close()
            // and this functions may do nothing until the next item is opened.
            // The last list item has been closed on opening the table!
            //$this->list_interrupted = null;
        }
    }

    /*
     * Tables
     */

    /**
     * Start a table
     *
     * @param int $maxcols maximum number of columns
     * @param int $numrows NOT IMPLEMENTED
     */
    function table_open($maxcols = NULL, $numrows = NULL){
        // Do additional actions required if we are in a list
        $this->interruptList ();
        
        $this->doc .= '<table:table>';
        for($i=0; $i<$maxcols; $i++){
            $this->doc .= '<table:table-column />';
        }
    }

    function table_close(){
        $this->doc .= '</table:table>';

        // Do additional actions required if we interripted a list,
        // see table_open()
        $this->continueList ();
    }

    function tablerow_open(){
        $this->doc .= '<table:table-row>';
    }

    function tablerow_close(){
        $this->doc .= '</table:table-row>';

        // Reset temporary column counter
        //$this->temp_column = 0;
    }

    /**
     * Open a table header cell
     *
     * @param int    $colspan
     * @param string $align left|center|right
     * @param int    $rowspan
     */
    function tableheader_open($colspan = 1, $align = "left", $rowspan = 1){
        $this->doc .= '<table:table-cell office:value-type="string" table:style-name="'.$this->styleset->getStyleName('table header').'" ';
        //$this->doc .= ' table:style-name="tablealign'.$align.'"';
        if ( $colspan > 1 ) {
            $this->doc .= ' table:number-columns-spanned="'.$colspan.'"';
        }
        if ( $rowspan > 1 ) {
            $this->doc .= ' table:number-rows-spanned="'.$rowspan.'"';
        }
        $this->doc .= '>';
        $this->p_open($this->styleset->getStyleName('table heading'));
    }

    function tableheader_close(){
        $this->p_close();
        $this->doc .= '</table:table-cell>';
    }

    /**
     * Open a table cell
     *
     * @param int    $colspan
     * @param string $align left|center|right
     * @param int    $rowspan
     */
    function tablecell_open($colspan = 1, $align = "left", $rowspan = 1){
        $this->doc .= '<table:table-cell office:value-type="string" table:style-name="'.$this->styleset->getStyleName('table cell').'" ';
        if ( $colspan > 1 ) {
            $this->doc .= ' table:number-columns-spanned="'.$colspan.'"';
        }
        if ( $rowspan > 1 ) {
            $this->doc .= ' table:number-rows-spanned="'.$rowspan.'"';
        }
        $this->doc .= '>';
        if (!$align) $align = "left";
        $style = $this->styleset->getStyleName('tablealign '.$align);
        $this->p_open($style);
    }

    function tablecell_close(){
        $this->p_close();
        $this->doc .= '</table:table-cell>';
    }

    /**
     * Callback for footnote start syntax
     *
     * All following content will go to the footnote instead of
     * the document. To achieve this the previous rendered content
     * is moved to $store and $doc is cleared
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function footnote_open() {

        // move current content to store and record footnote
        $this->store = $this->doc;
        $this->doc   = '';
    }

    /**
     * Callback for footnote end syntax
     *
     * All rendered content is moved to the $footnotes array and the old
     * content is restored from $store again
     *
     * @author Andreas Gohr
     */
    function footnote_close() {
        // recover footnote into the stack and restore old content
        $footnote = $this->doc;
        $this->doc = $this->store;
        $this->store = '';

        // check to see if this footnote has been seen before
        $i = array_search($footnote, $this->footnotes);

        if ($i === false) {
            $i = count($this->footnotes);
            // its a new footnote, add it to the $footnotes array
            $this->footnotes[$i] = $footnote;

            $this->doc .= '<text:note text:id="ftn'.$i.'" text:note-class="footnote">';
            $this->doc .= '<text:note-citation>'.($i+1).'</text:note-citation>';
            $this->doc .= '<text:note-body>';
            //FIXME: Can break document if paragraphs have been opened inside of $footnote!!!
            $this->doc .= '<text:p text:style-name="'.$this->styleset->getStyleName('footnote').'">';
            $this->doc .= $footnote;
            $this->doc .= '</text:p>';
            $this->doc .= '</text:note-body>';
            $this->doc .= '</text:note>';

        } else {
            // seen this one before - just reference it FIXME: style isn't correct yet
            $this->doc .= '<text:note-ref text:note-class="footnote" text:ref-name="ftn'.$i.'">'.($i+1).'</text:note-ref>';
        }
    }

    function listu_open($continue=false) {
        $this->p_close();
        $this->doc .= '<text:list text:style-name="'.$this->styleset->getStyleName('list').'"';
        if ($continue) {
            $this->doc .= ' text:continue-numbering="true" ';
        }
        $this->doc .= '>';
        $this->in_list = 'listu';
    }

    function listu_close() {
        $this->doc .= '</text:list>';
        $this->in_list = null;
    }

    function listo_open($continue=false) {
        $this->p_close();
        $this->doc .= '<text:list text:style-name="'.$this->styleset->getStyleName('numbering').'"';
        if ($continue) {
            $this->doc .= ' text:continue-numbering="true" ';
        }
        $this->doc .= '>';
        $this->in_list = 'listo';
    }

    function listo_close() {
        $this->doc .= '</text:list>';
        $this->in_list = null;
    }
    /**
     * Open a list item
     *
     * @param int $level the nesting level
     */
    function listitem_open($level) {
        $this->list_interrupted = null;
        $this->in_list_item = true;
        $this->doc .= '<text:list-item>';
    }

    function listitem_close() {
        if ($this->list_interrupted != null) {
            // Do not do anything as long as list is interrupted
            return;
        }
        $this->in_list_item = false;
        $this->doc .= '</text:list-item>';
    }

    function listcontent_open() {
        $this->doc .= '<text:p text:style-name="'.$this->styleset->getStyleName('body').'">';
    }

    function listcontent_close() {
        if ($this->list_interrupted != null) {
            // Do not do anything as long as list is interrupted
            return;
        }
        $this->doc .= '</text:p>';
    }

    /**
     * Output unformatted $text
     *
     * @param string $text
     */
    function unformatted($text) {
        $this->doc .= $this->_xmlEntities($text);
    }

    /**
     * Format an acronym
     *
     * @param string $acronym
     */
    function acronym($acronym) {
        $this->doc .= $this->_xmlEntities($acronym);
    }

    /**
     * @param string $smiley
     */
    function smiley($smiley) {
        if ( array_key_exists($smiley, $this->smileys) ) {
            $src = DOKU_INC."lib/images/smileys/".$this->smileys[$smiley];
            $this->_odtAddImage($src);
        } else {
            $this->doc .= $this->_xmlEntities($smiley);
        }
    }

    /**
     * Format an entity
     *
     * @param string $entity
     */
    function entity($entity) {
        # UTF-8 entity decoding is broken in PHP <5
        if (version_compare(phpversion(), "5.0.0") and array_key_exists($entity, $this->entities) ) {
            # decoding may fail for missing Multibyte-Support in entity_decode
            $dec = @html_entity_decode($this->entities[$entity],ENT_NOQUOTES,'UTF-8');
            if($dec){
                $this->doc .= $this->_xmlEntities($dec);
            }else{
                $this->doc .= $this->_xmlEntities($entity);
            }
        } else {
            $this->doc .= $this->_xmlEntities($entity);
        }
    }

    /**
     * Typographically format a multiply sign
     *
     * Example: ($x=640, $y=480) should result in "640×480"
     *
     * @param string|int $x first value
     * @param string|int $y second value
     */
    function multiplyentity($x, $y) {
        $this->doc .= $x.'×'.$y;
    }

    function singlequoteopening() {
        global $lang;
        $this->doc .= $lang['singlequoteopening'];
    }

    function singlequoteclosing() {
        global $lang;
        $this->doc .= $lang['singlequoteclosing'];
    }

    function apostrophe() {
        global $lang;
        $this->doc .= $lang['apostrophe'];
    }

    function doublequoteopening() {
        global $lang;
        $this->doc .= $lang['doublequoteopening'];
    }

    function doublequoteclosing() {
        global $lang;
        $this->doc .= $lang['doublequoteclosing'];
    }

    /**
     * Output inline PHP code
     *
     * @param string $text The PHP code
     */
    function php($text) {
        $this->monospace_open();
        $this->doc .= $this->_xmlEntities($text);
        $this->monospace_close();
    }

    /**
     * Output block level PHP code
     *
     * @param string $text The PHP code
     */
    function phpblock($text) {
        $this->file($text);
    }

    /**
     * Output raw inline HTML
     *
     * @param string $text The HTML
     */
    function html($text) {
        $this->monospace_open();
        $this->doc .= $this->_xmlEntities($text);
        $this->monospace_close();
    }

    /**
     * Output raw block-level HTML
     *
     * @param string $text The HTML
     */
    function htmlblock($text) {
        $this->file($text);
    }

    /**
     * static call back to replace spaces
     *
     * @param array $matches
     * @return string
     */
    function _preserveSpace($matches){
        $spaces = $matches[1];
        $len    = strlen($spaces);
        return '<text:s text:c="'.$len.'"/>';
    }

    /**
     * Output preformatted text
     *
     * @param string $text
     */
    function preformatted($text) {
        $this->_preformatted($text);
    }

    /**
     * Display text as file content, optionally syntax highlighted
     *
     * @param string $text text to show
     * @param string $language programming language to use for syntax highlighting
     * @param string $filename file path label
     */
    function file($text, $language=null, $filename=null) {
        $this->_highlight('file', $text, $language);
    }

    function quote_open() {
        // Do not go higher than 5 because only 5 quotation styles are defined.
        if ( $this->quote_depth < 5 ) {
            $this->quote_depth++;
        }
        $quotation1 = $this->styleset->getStyleName('quotation1');
        if ($this->quote_depth == 1) {
            // On quote level 1 open a new paragraph with 'quotation1' style
            $this->p_close();
            $this->quote_pos = strlen ($this->doc);
            $this->p_open($quotation1);
            $this->quote_pos = strpos ($this->doc, $quotation1, $this->quote_pos);
            $this->quote_pos += strlen($quotation1) - 1;
        } else {
            // Quote level is greater than 1. Set new style by just changing the number.
            // This is possible because the styles in style.xml are named 'Quotation 1', 'Quotation 2'...
            // FIXME: Unsafe as we now use freely choosen names per template class
            $this->doc [$this->quote_pos] = $this->quote_depth;
        }
    }

    function quote_close() {
        if ( $this->quote_depth > 0 ) {
            $this->quote_depth--;
        }
        if ($this->quote_depth == 0 && $this->in_paragraph) { // only close the paragraph if we're actually in one
            $this->p_close();
        }
    }

    /**
     * Display text as code content, optionally syntax highlighted
     *
     * @param string $text text to show
     * @param string $language programming language to use for syntax highlighting
     * @param string $filename file path label
     */
    function code($text, $language=null, $filename=null) {
        $this->_highlight('code', $text, $language);
    }

    /**
     * @param string $text
     * @param string $style
     * @param bool $notescaped
     */
    function _preformatted($text, $style=null, $notescaped=true) {
        if (empty($style)) {
            $style = $this->styleset->getStyleName('preformatted');
        }
        if ($notescaped) {
            $text = $this->_xmlEntities($text);
        }
        if (strpos($text, "\n") !== FALSE and strpos($text, "\n") == 0) {
            // text starts with a newline, remove it
            $text = substr($text,1);
        }
        $text = str_replace("\n",'<text:line-break/>',$text);
        $text = str_replace("\t",'<text:tab/>',$text);
        $text = preg_replace_callback('/(  +)/',array($this,'_preserveSpace'),$text);

        if ($this->in_list_item) { // if we're in a list item, we must close the <text:p> tag
            $this->doc .= '</text:p>';
            $this->doc .= '<text:p text:style-name="'.$style.'">';
            $this->doc .= $text;
            $this->doc .= '</text:p>';
            $this->doc .= '<text:p>';
        } else {
            $this->doc .= '<text:p text:style-name="'.$style.'">';
            $this->doc .= $text;
            $this->doc .= '</text:p>';
        }
    }

    /**
     * @param string $type
     * @param string $text
     * @param string $language
     */
    function _highlight($type, $text, $language=null) {
        $style_name = $this->styleset->getStyleName('source code');
        if ($type == "file") $style_name = $this->styleset->getStyleName('source file');

        if (is_null($language)) {
            $this->_preformatted($text, $style_name);
            return;
        }

        // from inc/parserutils.php:p_xhtml_cached_geshi()
        $geshi = new GeSHi($text, $language, DOKU_INC . 'inc/geshi');
        $geshi->set_encoding('utf-8');
        // $geshi->enable_classes(); DO NOT WANT !
        $geshi->set_header_type(GESHI_HEADER_PRE);
        $geshi->enable_keyword_links(false);

        // remove GeSHi's wrapper element (we'll replace it with our own later)
        // we need to use a GeSHi wrapper to avoid <BR> throughout the highlighted text
        $highlighted_code = trim(preg_replace('!^<pre[^>]*>|</pre>$!','',$geshi->parse_code()),"\n\r");
        // remove useless leading and trailing whitespace-newlines
        $highlighted_code = preg_replace('/^&nbsp;\n/','',$highlighted_code);
        $highlighted_code = preg_replace('/\n&nbsp;$/','',$highlighted_code);
        // replace styles
        $highlighted_code = str_replace("</span>", "</text:span>", $highlighted_code);
        $highlighted_code = preg_replace_callback('/<span style="([^"]+)">/', array($this,'_convert_css_styles'), $highlighted_code);
        // cleanup leftover span tags
        $highlighted_code = preg_replace('/<span[^>]*>/', "<text:span>", $highlighted_code);
        $highlighted_code = str_replace("&nbsp;", "&#xA0;", $highlighted_code);
        $this->_preformatted($highlighted_code, $style_name, false);
    }

    /**
     * @param array $matches
     * @return string
     */
    function _convert_css_styles($matches) {
        $all_css_styles = $matches[1];
        // parse the CSS attribute
        $css_styles = array();
        foreach(explode(";", $all_css_styles) as $css_style) {
            $css_style_array = explode(":", $css_style);
            if (!trim($css_style_array[0]) or !trim($css_style_array[1])) {
                continue;
            }
            $css_styles[trim($css_style_array[0])] = trim($css_style_array[1]);
        }
        // create the ODT xml style
        $style_name = "highlight." . $this->highlight_style_num;
        $this->highlight_style_num += 1;
        $style_content = '
            <style:style style:name="'.$style_name.'" style:family="text">
                <style:text-properties ';
        foreach($css_styles as $style_key=>$style_value) {
            // Hats off to those who thought out the OpenDocument spec: styling syntax is similar to CSS !
            $style_content .= 'fo:'.$style_key.'="'.$style_value.'" ';
        }
        $style_content .= '/>
            </style:style>';
        // add the style to the library
        $this->autostyles[$style_name] = $style_content;
        // now make use of the new style
        return '<text:span text:style-name="'.$style_name.'">';
    }

    /**
     * Render an internal media file
     *
     * @param string $src       media ID
     * @param string $title     descriptive text
     * @param string $align     left|center|right
     * @param int    $width     width of media in pixel
     * @param int    $height    height of media in pixel
     * @param string $cache     cache|recache|nocache
     * @param string $linking   linkonly|detail|nolink
     * @param bool   $returnonly whether to return odt or write to doc attribute
     */
    function internalmedia ($src, $title=NULL, $align=NULL, $width=NULL,
                            $height=NULL, $cache=NULL, $linking=NULL, $returnonly = false) {
        global $ID;
        resolve_mediaid(getNS($ID),$src, $exists);
        list(/* $ext */,$mime) = mimetype($src);

        if(substr($mime,0,5) == 'image'){
            $file = mediaFN($src);
            if($returnonly) {
              return $this->_odtAddImage($file, $width, $height, $align, $title, true);
            } else {
              $this->_odtAddImage($file, $width, $height, $align, $title);
            }
        }else{
/*
            // FIXME build absolute medialink and call externallink()
            $this->code('FIXME internalmedia: '.$src);
*/
            //FIX by EPO/Intersel - create a link to the dokuwiki internal resource
            if (empty($title)) {$title=explode(':',$src); $title=end($title);}
            if($returnonly) {
              return $this->externalmedia(str_replace('doku.php?id=','lib/exe/fetch.php?media=',wl($src,'',true)),$title,
                                        null, null, null, null, null, true);
            } else {
              $this->externalmedia(str_replace('doku.php?id=','lib/exe/fetch.php?media=',wl($src,'',true)),$title,
                                        null, null, null, null, null);
            }
            //End of FIX
        }
    }

    /**
     * Render an external media file
     *
     * @param string $src        full media URL
     * @param string $title      descriptive text
     * @param string $align      left|center|right
     * @param int    $width      width of media in pixel
     * @param int    $height     height of media in pixel
     * @param string $cache      cache|recache|nocache
     * @param string $linking    linkonly|detail|nolink
     * @param bool   $returnonly whether to return odt or write to doc attribute
     */
    function externalmedia ($src, $title=NULL, $align=NULL, $width=NULL,
                            $height=NULL, $cache=NULL, $linking=NULL, $returnonly = false) {
        list($ext,$mime) = mimetype($src);

        if(substr($mime,0,5) == 'image'){
            $tmp_dir = $this->config->getParam ('tmpdir')."/odt";
            $tmp_name = $tmp_dir."/".md5($src).'.'.$ext;
            $final_name = 'Pictures/'.md5($tmp_name).'.'.$ext;
            if(!$this->docHandler->fileExists($final_name)){
                $client = new DokuHTTPClient;
                $img = $client->get($src);
                if ($img === FALSE) {
                    $tmp_name = $src; // fallback to a simple link
                } else {
                    if (!is_dir($tmp_dir)) io_mkdir_p($tmp_dir);
                    $tmp_img = fopen($tmp_name, "w") or die("Can't create temp file $tmp_img");
                    fwrite($tmp_img, $img);
                    fclose($tmp_img);
                }
            }
            if($returnonly) {
              $ret = $this->_odtAddImage($tmp_name, $width, $height, $align, $title, true);
              if (file_exists($tmp_name)) unlink($tmp_name);
              return $ret;
            } else {
              $this->_odtAddImage($tmp_name, $width, $height, $align, $title);
              if (file_exists($tmp_name)) unlink($tmp_name);
            }
        }else{
            if($returnonly) {
              return $this->externallink($src,$title,true);
            } else {
              $this->externallink($src,$title);
            }
        }
    }

    /**
     * Render a CamelCase link
     *
     * @param string $link       The link name
     * @param bool   $returnonly whether to return odt or write to doc attribute
     * @see http://en.wikipedia.org/wiki/CamelCase
     */
    function camelcaselink($link, $returnonly) {
        if($returnonly) {
          return $this->internallink($link,$link, null, true);
        } else {
          $this->internallink($link, $link);
        }
    }

    /**
     * @param string $id
     * @param string $name
     */
    function reference($id, $name = NULL) {
        $ret = '<text:a xlink:type="simple" xlink:href="#'.$id.'"';
        if ($name) {
            $ret .= '>'.$this->_xmlEntities($name).'</text:a>';
        } else {
            $ret .= '/>';
        }
        return $ret;
    }

    /**
     * Render a wiki internal link
     *
     * @param string       $id         page ID to link to. eg. 'wiki:syntax'
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function internallink($id, $name = NULL, $returnonly = false) {
        global $ID;
        // default name is based on $id as given
        $default = $this->_simpleTitle($id);
        // now first resolve and clean up the $id
        resolve_pageid(getNS($ID),$id,$exists);
        $name = $this->_getLinkTitle($name, $default, $isImage, $id);

        // build the absolute URL (keeping a hash if any)
        list($id,$hash) = explode('#',$id,2);
        $url = wl($id,'',true);
        if($hash) $url .='#'.$hash;

        if ($ID == $id) {
          if($returnonly) {
            return $this->reference($hash, $name);
          } else {
            $this->doc .= $this->reference($hash, $name);
          }
        } else {
          if($returnonly) {
            return $this->_doLink($url,$name);
          } else {
            $this->doc .= $this->_doLink($url,$name);
          }
        }
    }

    /**
     * Add external link
     *
     * @param string       $url        full URL with scheme
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function externallink($url, $name = NULL, $returnonly = false) {
        $name = $this->_getLinkTitle($name, $url, $isImage);

        if($returnonly) {
          return $this->_doLink($url,$name,$returnonly);
        } else {
          $this->doc .= $this->_doLink($url,$name);
        }
    }

    /**
     * Replace local links with bookmark references or text
     */
    protected function insert_locallinks() {
        $matches = array();
        $position = 0;
        $max = strlen ($this->doc);
        $length = strlen ('<locallink>');
        $length_with_name = strlen ('<locallink name=');
        while ( $position < $max ) {
            $first = strpos ($this->doc, '<locallink', $position);
            if ( $first === false ) {
                break;
            }
            $end_first = strpos ($this->doc, '>', $first);
            if ( $end_first === false ) {
                break;
            }
            $second = strpos ($this->doc, '</locallink>', $end_first);
            if ( $second === false ) {
                break;
            }

            // $match includes the whole tag '<locallink name="...">text</locallink>'
            // The attribute 'name' is optional!
            $match = substr ($this->doc, $first, $second - $first + $length + 1);
            $text = substr ($match, $end_first-$first+1, -($length + 1));
            $text = trim ($text, ' ');
            $text = strtolower ($text);
            $page = str_replace (' ', '_', $text);
            $opentag = substr ($match, 0, $end_first-$first);
            $name = substr ($opentag, $length_with_name);
            $name = trim ($name, '">');

            $found = false;
            foreach ($this->toc as $item) {
                $params = explode (',', $item);
                if ( $page == $params [1] ) {
                    $found = true;
                    $link  = '<text:a xlink:type="simple" xlink:href="#'.$params [0].'">';
                    $link .= $text;
                    $link .= '</text:a>';

                    $this->doc = str_replace ($match, $link, $this->doc);
                    $position = $first + strlen ($link);
                }
            }

            if ( $found == false ) {
                // Nothing found yet, check the bookmarks too.
                foreach ($this->bookmarks as $item) {
                    if ( $page == $item ) {
                        $found = true;
                        $link  = '<text:a xlink:type="simple" xlink:href="#'.$item.'">';
                        if ( !empty($name) ) {
                            $link .= $name;
                        } else {
                            $link .= $text;
                        }
                        $link .= '</text:a>';

                        $this->doc = str_replace ($match, $link, $this->doc);
                        $position = $first + strlen ($link);
                    }
                }
            }

            if ( $found == false ) {
                // If we get here, then the referenced target was not found.
                // There must be a bug manging the bookmarks or links!
                // At least remove the locallink element and insert text.
                if ( !empty($name) ) {
                    $this->doc = str_replace ($match, $name, $this->doc);
                } else {
                    $this->doc = str_replace ($match, $text, $this->doc);
                }
                $position = $first + strlen ($text);
            }
        }
    }

    /**
     * Insert local link placeholder with name.
     * The reference will be resolved on calling insert_locallinks();
     *
     * @fixme add image handling
     *
     * @param string $hash hash link identifier
     * @param string $id   name for the link (the reference)
     * @param string $name text for the link (text inserted instead of reference)
     */
    function locallink_with_name($hash, $id = NULL, $name = NULL){
        $id  = $this->_getLinkTitle($id, $hash, $isImage);
        $this->doc .= '<locallink name="'.$name.'">'.$id.'</locallink>';
    }

    /**
     * Insert local link placeholder.
     * The reference will be resolved on calling insert_locallinks();
     *
     * @fixme add image handling
     *
     * @param string $hash hash link identifier
     * @param string $name name for the link
     */
    function locallink($hash, $name = NULL){
        $name  = $this->_getLinkTitle($name, $hash, $isImage);
        $this->doc .= '<locallink>'.$name.'</locallink>';
    }

    /**
     * Render an interwiki link
     *
     * You may want to use $this->_resolveInterWiki() here
     *
     * @param string       $match      original link - probably not much use
     * @param string|array $name       name for the link, array for media file
     * @param string       $wikiName   indentifier (shortcut) for the remote wiki
     * @param string       $wikiUri    the fragment parsed from the original link
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function interwikilink($match, $name = NULL, $wikiName, $wikiUri, $returnonly = false) {
        $name  = $this->_getLinkTitle($name, $wikiUri, $isImage);
        $url = $this-> _resolveInterWiki($wikiName,$wikiUri);
        if($returnonly) {
          return $this->_doLink($url,$name);
        } else {
          $this->doc .= $this->_doLink($url,$name);
        }
    }

    /**
     * Just print WindowsShare links
     *
     * @fixme add image handling
     *
     * @param string       $url        the link
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function windowssharelink($url, $name = NULL,$returnonly) {
        $name  = $this->_getLinkTitle($name, $url, $isImage);
        if($returnonly) {
          return $name;
        } else {
          $this->doc .= $name;
        }
    }

    /**
     * Just print email links
     *
     * @fixme add image handling
     *
     * @param string       $address    Email-Address
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function emaillink($address, $name = NULL, $returnonly) {
        $name  = $this->_getLinkTitle($name, $address, $isImage);
        if($returnonly) {
          return $this->_doLink("mailto:".$address,$name);
        } else {
          $this->doc .= $this->_doLink("mailto:".$address,$name);
        }
    }

    /**
     * Add a hyperlink, handling Images correctly
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     *
     * @param string $url
     * @param string|array $name
     */
    function _doLink($url,$name){
        $url = $this->_xmlEntities($url);
        $doc = '';
        if(is_array($name)){
            // Images
            if($url && !$this->config->getParam ('disable_links')) $doc .= '<draw:a xlink:type="simple" xlink:href="'.$url.'">';

            if($name['type'] == 'internalmedia'){
                $doc .= $this->internalmedia($name['src'],
                                     $name['title'],
                                     $name['align'],
                                     $name['width'],
                                     $name['height'],
                                     $name['cache'],
                                     $name['linking'],
                                     true);
            }

            if($url && !$this->config->getParam ('disable_links')) $doc .= '</draw:a>';
        }else{
            // Text
            if($url && !$this->config->getParam ('disable_links')) $doc .= '<text:a xlink:type="simple" xlink:href="'.$url.'">';
            $doc .= $name; // we get the name already XML encoded
            if($url && !$this->config->getParam ('disable_links')) $doc .= '</text:a>';
        }
        return $doc;
    }

    /**
     * Construct a title and handle images in titles
     *
     * @author Harry Fuecks <hfuecks@gmail.com>
     *
     * @param string|array|null $title
     * @param string $default
     * @param bool|null $isImage
     * @param string $id
     * @return mixed
     */
    function _getLinkTitle($title, $default, & $isImage, $id=null) {
        $isImage = false;
        if (is_null($title) || trim($title) == '') {
            if ($this->config->getParam ('useheading') && $id) {
                $heading = p_get_first_heading($id);
                if ($heading) {
                    return $this->_xmlEntities($heading);
                }
            }
            return $this->_xmlEntities($default);
        } else if ( is_array($title) ) {
            $isImage = true;
            return $title;
        } else {
            return $this->_xmlEntities($title);
        }
    }

    /**
     * Creates a linkid from a headline
     *
     * @param string $title The headline title
     * @param boolean $create Create a new unique ID?
     * @return string
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function _headerToLink($title,$create=false) {
        $title = str_replace(':','',cleanID($title));
        $title = ltrim($title,'0123456789._-');
        if(empty($title)) {
            $title='section';
        }

        if($create){
            // make sure tiles are unique
            $num = '';
            while(in_array($title.$num,$this->headers)){
                ($num) ? $num++ : $num = 1;
            }
            $title = $title.$num;
            $this->headers[] = $title;
        }

        return $title;
    }

    /**
     * @param string $value
     * @return string
     */
    function _xmlEntities($value) {
        return str_replace( array('&','"',"'",'<','>'), array('&#38;','&#34;','&#39;','&#60;','&#62;'), $value);
    }

    /**
     * Render the output of an RSS feed
     *
     * @param string $url    URL of the feed
     * @param array  $params Finetuning of the output
     */
    function rss ($url,$params){
        global $lang;

        require_once(DOKU_INC . 'inc/FeedParser.php');
        $feed = new FeedParser();
        $feed->feed_url($url);

        //disable warning while fetching
        $elvl = null;
        if (!defined('DOKU_E_LEVEL')) { $elvl = error_reporting(E_ERROR); }
        $rc = $feed->init();
        if (!defined('DOKU_E_LEVEL')) { error_reporting($elvl); }

        //decide on start and end
        if($params['reverse']){
            $mod = -1;
            $start = $feed->get_item_quantity()-1;
            $end   = $start - ($params['max']);
            $end   = ($end < -1) ? -1 : $end;
        }else{
            $mod   = 1;
            $start = 0;
            $end   = $feed->get_item_quantity();
            $end   = ($end > $params['max']) ? $params['max'] : $end;;
        }

        $this->listu_open();
        if($rc){
            for ($x = $start; $x != $end; $x += $mod) {
                $item = $feed->get_item($x);
                $this->listitem_open(0);
                $this->listcontent_open();
                $this->externallink($item->get_permalink(),
                                    $item->get_title());
                if($params['author']){
                    $author = $item->get_author(0);
                    if($author){
                        $name = $author->get_name();
                        if(!$name) $name = $author->get_email();
                        if($name) $this->cdata(' '.$lang['by'].' '.$name);
                    }
                }
                if($params['date']){
                    $this->cdata(' ('.$item->get_date($this->config->getParam ('dformat')).')');
                }
                if($params['details']){
                    $this->cdata(strip_tags($item->get_description()));
                }
                $this->listcontent_close();
                $this->listitem_close();
            }
        }else{
            $this->listitem_open(0);
            $this->listcontent_open();
            $this->emphasis_open();
            $this->cdata($lang['rssfailed']);
            $this->emphasis_close();
            $this->externallink($url);
            $this->listcontent_close();
            $this->listitem_close();
        }
        $this->listu_close();
    }

    /**
     * Adds the content of $string as a SVG picture to the document.
     * The other parameters behave in the same way as in _odtAddImage.
     *
     * @author LarsDW223
     *
     * @param string $string
     * @param  $width
     * @param  $height
     * @param  $align
     * @param  $title
     * @param  $style
     */
    function _addStringAsSVGImage($string, $width = NULL, $height = NULL, $align = NULL, $title = NULL, $style = NULL) {

        if ( empty($string) ) { return; }

        $ext  = '.svg';
        $mime = '.image/svg+xml';
        $name = 'Pictures/'.md5($string).'.'.$ext;
        $this->docHandler->addFile($name, $mime, $string);

        // make sure width and height are available
        if (!$width || !$height) {
            list($width, $height) = $this->_odtGetImageSize($string, $width, $height);
        }

        if($align){
            $anchor = 'paragraph';
        }else{
            $anchor = 'as-char';
        }

        if (!$style or !array_key_exists($style, $this->autostyles)) {
            $style = $this->styleset->getStyleName('media '.$align);
        }

        if ($title) {
            $this->doc .= '<draw:frame draw:style-name="'.$style.'" draw:name="'.$this->_xmlEntities($title).' Legend"
                            text:anchor-type="'.$anchor.'" draw:z-index="0" svg:width="'.$width.'">';
            $this->doc .= '<draw:text-box>';
            $this->doc .= '<text:p text:style-name="'.$this->styleset->getStyleName('legend center').'">';
        }
        $this->doc .= '<draw:frame draw:style-name="'.$style.'" draw:name="'.$this->_xmlEntities($title).'"
                        text:anchor-type="'.$anchor.'" draw:z-index="0"
                        svg:width="'.$width.'" svg:height="'.$height.'" >';
        $this->doc .= '<draw:image xlink:href="'.$this->_xmlEntities($name).'"
                        xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>';
        $this->doc .= '</draw:frame>';
        if ($title) {
            $this->doc .= $this->_xmlEntities($title).'</text:p></draw:text-box></draw:frame>';
        }
    }

    /**
     * Adds the image $src as a picture file without adding it to the content
     * of the document. The link name which can be used for the ODT draw:image xlink:href
     * is returned. The caller is responsible for creating the frame and image tag
     * but therefore has full control over it. This means he can also set parameters
     * in the odt frame and image tag which can not be changed using the function _odtAddImage.
     *
     * @author LarsDW223
     *
     * @param string $src
     * @return string
     */
    function _odtAddImageAsFileOnly($src){
        $name = '';
        if (file_exists($src)) {
            list($ext,$mime) = mimetype($src);
            $name = 'Pictures/'.md5($src).'.'.$ext;
            $this->docHandler->addFile($name, $mime, io_readfile($src,false));
        }
        return $name;
    }

    /**
     * @param string $src
     * @param  $width
     * @param  $height
     * @param  $align
     * @param  $title
     * @param  $style
     * @param  $returnonly
     */
    function _odtAddImage($src, $width = NULL, $height = NULL, $align = NULL, $title = NULL, $style = NULL, $returnonly = false){
        $doc = '';
        if (file_exists($src)) {
            list($ext,$mime) = mimetype($src);
            $name = 'Pictures/'.md5($src).'.'.$ext;
            $this->docHandler->addFile($name, $mime, io_readfile($src,false));
        } else {
            $name = $src;
        }
        // make sure width and height are available
        if (!$width || !$height) {
            list($width, $height) = $this->_odtGetImageSize($src, $width, $height);
        } else {
            // Adjust values for ODT
            $width = $this->adjustXLengthValueForODT ($width);
            $height = $this->adjustYLengthValueForODT ($height);
        }

        if($align){
            $anchor = 'paragraph';
        }else{
            $anchor = 'as-char';
        }

        if (!$style or !array_key_exists($style, $this->autostyles)) {
            $style = $this->styleset->getStyleName('media '.$align);
        }

        if ($title) {
            $doc .= '<draw:frame draw:style-name="'.$style.'" draw:name="'.$this->_xmlEntities($title).' Legend"
                            text:anchor-type="'.$anchor.'" draw:z-index="0" svg:width="'.$width.'">';
            $doc .= '<draw:text-box>';
            $doc .= '<text:p text:style-name="'.$this->styleset->getStyleName('legend center').'">';
        }
        $doc .= '<draw:frame draw:style-name="'.$style.'" draw:name="'.$this->_xmlEntities($title).'"
                        text:anchor-type="'.$anchor.'" draw:z-index="0"
                        svg:width="'.$width.'" svg:height="'.$height.'" >';
        $doc .= '<draw:image xlink:href="'.$this->_xmlEntities($name).'"
                        xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>';
        $doc .= '</draw:frame>';
        if ($title) {
            $doc .= $this->_xmlEntities($title).'</text:p></draw:text-box></draw:frame>';
        }
        if($returnonly) {
          return $doc;
        } else {
          $this->doc .= $doc;
        }
    }

    /**
     * @param string $src
     * @param  $width
     * @param  $height
     * @return array
     */
    function _odtGetImageSize($src, $width = NULL, $height = NULL){
        if (file_exists($src)) {
            $info  = getimagesize($src);
            if(!$width){
                $width  = $info[0];
                $height = $info[1];
            }else{
                $height = round(($width * $info[1]) / $info[0]);
            }
        }

        // convert from pixel to centimeters
        if ($width) $width = (($width/96.0)*2.54);
        if ($height) $height = (($height/96.0)*2.54);

        if ($width && $height) {
            // Don't be wider than the page
            if ($width >= 17){ // FIXME : this assumes A4 page format with 2cm margins
                $width = $width.'cm"  style:rel-width="100%';
                $height = $height.'cm"  style:rel-height="scale';
            } else {
                $width = $width.'cm';
                $height = $height.'cm';
            }
        } else {
            // external image and unable to download, fallback
            if ($width) {
                $width = $width."cm";
            } else {
                $width = '" svg:rel-width="100%';
            }
            if ($height) {
                $height = $height."cm";
            } else {
                $height = '" svg:rel-height="100%';
            }
        }
        return array($width, $height);
    }

    /**
     * This function opens a new span using the style as set in the imported CSS $import.
     * So, the function requires the helper class 'helper_plugin_odt_cssimport'.
     * The CSS style is selected by the element type 'span' and the specified classes in $classes.
     * The property 'background-image' is not supported by an ODT span. This will be emulated
     * by inserting an image manually in the span. If the url from the CSS should be converted to
     * a local path, then the caller can specify a $baseURL. The full path will then be $baseURL/background-image.
     *
     * This function calls _odtSpanOpenUseProperties. See the function description for supported properties.
     *
     * The span should be closed by calling '_odtSpanClose'.
     *
     * @author LarsDW223
     *
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param $baseURL
     * @param $element
     */
    function _odtSpanOpenUseCSS(helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL){
        $properties = array();
        if ( empty($element) ) {
            $element = 'span';
        }
        $this->_processCSSClass ($properties, $import, $classes, $baseURL, $element);
        $this->_odtSpanOpenUseProperties($properties);
    }

    /**
     * This function opens a new span using the style as specified in $style.
     * The property 'background-image' is not supported by an ODT span. This will be emulated
     * by inserting an image manually in the span. If the url from the CSS should be converted to
     * a local path, then the caller can specify a $baseURL. The full path will then be $baseURL/background-image.
     *
     * This function calls _odtSpanOpenUseProperties. See the function description for supported properties.
     *
     * The span should be closed by calling '_odtSpanClose'.
     *
     * @author LarsDW223
     *
     * @param $style
     * @param $baseURL
     */
    function _odtSpanOpenUseCSSStyle($style, $baseURL = NULL){
        $properties = array();
        $this->_processCSSStyle ($properties, $style, $baseURL);
        $this->_odtSpanOpenUseProperties($properties);
    }

    /**
     * This function opens a new span using the style as set in the assoziative array $properties.
     * The parameters in the array should be named as the CSS property names e.g. 'color' or 'background-color'.
     * The property 'background-image' is not supported by an ODT span. This will be emulated
     * by inserting an image manually in the span.
     *
     * background-color, color, font-style, font-weight, font-size, border, font-family, font-variant, letter-spacing,
     * vertical-align, background-image (emulated)
     *
     * The span should be closed by calling '_odtSpanClose'.
     *
     * @author LarsDW223
     *
     * @param array $properties
     */
    function _odtSpanOpenUseProperties($properties){
        $disabled = array ();

        $odt_bg = $properties ['background-color'];
        $picture = $properties ['background-image'];

        if ( !empty ($picture) ) {
            $this->style_count++;

            // If a picture/background-image is set, than we insert it manually here.
            // This is a workaround because ODT does not support the background-image attribute in a span.

            // Define graphic style for picture
            $style_name = 'odt_auto_style_span_graphic_'.$this->style_count;
            $image_style = '<style:style style:name="'.$style_name.'" style:family="graphic" style:parent-style-name="'.$this->styleset->getStyleName('graphics').'"><style:graphic-properties style:vertical-pos="middle" style:vertical-rel="text" style:horizontal-pos="from-left" style:horizontal-rel="paragraph" fo:background-color="'.$odt_bg.'" style:flow-with-text="true"></style:graphic-properties></style:style>';

            // Add style and image to our document
            $this->autostyles[$style_name] = $image_style;
            $this->_odtAddImage ($picture,NULL,NULL,NULL,NULL,$style_name);
        }

        // Create a text style for our span
        $disabled ['background-image'] = 1;
        $style_name = $this->_createTextStyle ($properties, $disabled);

        // Open span
        $this->doc .= '<text:span text:style-name="'.$style_name.'">';
    }

    /**
     * This function closes a span (previously opened with _odtSpanOpenUseCSS).
     *
     * @author LarsDW223
     */
    function _odtSpanClose(){
        $this->doc .= '</text:span>';
    }

    /**
     * This function opens a new paragraph using the style as set in the imported CSS $import.
     * So, the function requires the helper class 'helper_plugin_odt_cssimport'.
     * The CSS style is selected by the element type 'p' and the specified classes in $classes.
     * The property 'background-image' is emulated by inserting an image manually in the paragraph.
     * If the url from the CSS should be converted to a local path, then the caller can specify a $baseURL.
     * The full path will then be $baseURL/background-image.
     *
     * This function calls _odtParagraphOpenUseProperties. See the function description for supported properties.
     *
     * The span should be closed by calling '_odtParagraphClose'.
     *
     * @author LarsDW223
     *
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param $baseURL
     * @param $element
     */
    function _odtParagraphOpenUseCSS(helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL){
        $properties = array();
        if ( empty($element) ) {
            $element = 'p';
        }
        $this->_processCSSClass ($properties, $import, $classes, $baseURL, $element);
        $this->_odtParagraphOpenUseProperties($properties);
    }

    /**
     * This function opens a new paragraph using the style as specified in $style.
     * The property 'background-image' is emulated by inserting an image manually in the paragraph.
     * If the url from the CSS should be converted to a local path, then the caller can specify a $baseURL.
     * The full path will then be $baseURL/background-image.
     *
     * This function calls _odtParagraphOpenUseProperties. See the function description for supported properties.
     *
     * The paragraph must be closed by calling 'p_close'.
     *
     * @author LarsDW223
     *
     * @param $style
     * @param $baseURL
     */
    function _odtParagraphOpenUseCSSStyle($style, $baseURL = NULL){
        $properties = array();
        $this->_processCSSStyle ($properties, $style, $baseURL);
        $this->_odtParagraphOpenUseProperties($properties);
    }

    /**
     * This function opens a new paragraph using the style as set in the assoziative array $properties.
     * The parameters in the array should be named as the CSS property names e.g. 'color' or 'background-color'.
     * The property 'background-image' is emulated by inserting an image manually in the paragraph.
     *
     * The currently supported properties are:
     * background-color, color, font-style, font-weight, font-size, border, font-family, font-variant, letter-spacing,
     * vertical-align, line-height, background-image (emulated)
     *
     * The paragraph must be closed by calling 'p_close'.
     *
     * @author LarsDW223
     *
     * @param array $properties
     */
    function _odtParagraphOpenUseProperties($properties){
        $disabled = array ();

        if ($this->in_paragraph) {
            // opening a paragraph inside another paragraph is illegal
            return;
        }
        $this->in_paragraph = true;

        $odt_bg = $properties ['background-color'];
        $picture = $properties ['background-image'];

        if ( !empty ($picture) ) {
            // If a picture/background-image is set, than we insert it manually here.
            // This is a workaround because ODT background-image works different than in CSS.

            // Define graphic style for picture
            $this->style_count++;
            $style_name = 'odt_auto_style_span_graphic_'.$this->style_count;
            $image_style = '<style:style style:name="'.$style_name.'" style:family="graphic" style:parent-style-name="'.$this->styleset->getStyleName('graphics').'"><style:graphic-properties style:vertical-pos="middle" style:vertical-rel="text" style:horizontal-pos="from-left" style:horizontal-rel="paragraph" fo:background-color="'.$odt_bg.'" style:flow-with-text="true"></style:graphic-properties></style:style>';

            // Add style and image to our document
            $this->autostyles[$style_name] = $image_style;
            $this->_odtAddImage ($picture,NULL,NULL,NULL,NULL,$style_name);
        }

        // Create the style for the paragraph.
        $disabled ['background-image'] = 1;
        $style_name = $this->_createParagraphStyle ($properties, $disabled);

        // Open a paragraph
        $this->doc .= '<text:p text:style-name="'.$style_name.'">';
    }

    /**
     * This function opens a div. As divs are not supported by ODT, it will be exported as a frame.
     * To be more precise, to frames will be created. One including a picture nad the other including the text.
     * A picture frame will only be created if a 'background-image' is set in the CSS style.
     *
     * The currently supported CSS properties are:
     * background-color, color, padding, margin, display, border-radius, min-height.
     * The background-image is simulated using a picture frame.
     * FIXME: Find a way to successfuly use the background-image in the graphic style (see comments).
     *
     * The div should be closed by calling '_odtDivCloseAsFrame'.
     *
     * @author LarsDW223
     *
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param null $baseURL
     * @param null $element
     */
    function _odtDivOpenAsFrameUseCSS (helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL) {
        $this->in_div_as_frame++;
        if ( $this->in_div_as_frame > 1 ) {
            // Do not open a nested frame as this will make the content ofthe nested frame disappear.
            return;
        }

        $properties = array();

        $this->div_z_index += 5;
        $this->style_count++;

        if ( empty($element) ) {
            $element = 'div';
        }

        $import->getPropertiesForElement($properties, $element, $classes);
        foreach ($properties as $property => $value) {
            $properties [$property] = $this->adjustValueForODT ($value, 14);
        }
        $odt_bg = $properties ['background-color'];
        $odt_fo = $properties ['color'];
        $padding_left = $properties ['padding-left'];
        $padding_right = $properties ['padding-right'];
        $padding_top = $properties ['padding-top'];
        $padding_bottom = $properties ['padding-bottom'];
        $margin_left = $properties ['margin-left'];
        $margin_right = $properties ['margin-right'];
        $margin_top = $properties ['margin-top'];
        $margin_bottom = $properties ['margin-bottom'];
        $display = $properties ['display'];
        $fo_border = $properties ['border'];
        $radius = $properties ['border-radius'];
        $picture = $properties ['background-image'];
        $pic_positions = preg_split ('/\s/', $properties ['background-position']);

        $min_height = $properties ['min-height'];

        $pic_link = '';
        $pic_width = '';
        $pic_height = '';
        if ( !empty ($picture) ) {
            // If a picture/background-image is set in the CSS, than we insert it manually here.
            // This is a workaround because ODT does not support the background-image attribute in a span.

            if ( !empty ($baseURL) ) {
                // Replace 'url(...)' with $baseURL
                $picture = $import->replaceURLPrefix ($picture, $baseURL);
            }
            $pic_link=$this->_odtAddImageAsFileOnly($picture);
            list($pic_width, $pic_height) = $this->_odtGetImageSize($picture);
        }

        $horiz_pos = 'center';

        if ( empty ($width) ) {
            $width = '100%';
        }

        // Different handling for relative and absolute size...
        if ( $width [strlen($width)-1] == '%' ) {
            // Convert percentage values to absolute size, respecting page margins
            $width = trim($width, '%');
            $width_abs = $this->_getAbsWidthMindMargins ($width).'cm';
        } else {
            // Absolute values may include not supported units.
            // Adjust.
            $width_abs = $this->adjustXLengthValueForODT($width);
        }

        // Add our styles.
        $style_name = 'odt_auto_style_div_'.$this->style_count;

        $style =
         '<style:style style:name="'.$style_name.'_text_frame" style:family="graphic">
             <style:graphic-properties svg:stroke-color="'.$odt_bg.'"
                 draw:fill="solid" draw:fill-color="'.$odt_bg.'"
                 draw:textarea-horizontal-align="left"
                 draw:textarea-vertical-align="center"
                 style:horizontal-pos="'.$horiz_pos.'" fo:background-color="'.$odt_bg.'" style:background-transparency="100%" ';
        if ( !empty($padding_left) ) {
            $style .= 'fo:padding-left="'.$padding_left.'" ';
        }
        if ( !empty($padding_right) ) {
            $style .= 'fo:padding-right="'.$padding_right.'" ';
        }
        if ( !empty($padding_top) ) {
            $style .= 'fo:padding-top="'.$padding_top.'" ';
        }
        if ( !empty($padding_bottom) ) {
            $style .= 'fo:padding-bottom="'.$padding_bottom.'" ';
        }
        if ( !empty($margin_left) ) {
            $style .= 'fo:margin-left="'.$margin_left.'" ';
        }
        if ( !empty($margin_right) ) {
            $style .= 'fo:margin-right="'.$margin_right.'" ';
        }
        if ( !empty($margin_top) ) {
            $style .= 'fo:margin-top="'.$margin_top.'" ';
        }
        if ( !empty($margin_bottom) ) {
            $style .= 'fo:margin-bottom="'.$margin_bottom.'" ';
        }
        if ( !empty ($fo_border) ) {
            $style .= 'fo:border="'.$fo_border.'" ';
        }
        $style .= 'fo:min-height="'.$min_height.'"
                 style:wrap="none"';
        $style .= '>';

        // FIXME: Delete the part below 'if ( $picture != NULL ) {...}'
        // and use this background-image definition. For some reason the background-image is not displayed.
        // Help is welcome.
        /*$style .= '<style:background-image ';
        $style .= 'xlink:href="'.$pic_link.'" xlink:type="simple" xlink:actuate="onLoad"
                   style:position="center center" style:repeat="no-repeat" draw:opacity="100%"/>';*/
        $style .= '</style:graphic-properties>';
        $style .= '</style:style>';
        $style .= '<style:style style:name="'.$style_name.'_image_frame" style:family="graphic">
             <style:graphic-properties svg:stroke-color="'.$odt_bg.'"
                 draw:fill="none" draw:fill-color="'.$odt_bg.'"
                 draw:textarea-horizontal-align="left"
                 draw:textarea-vertical-align="center"
                 style:wrap="none"/>
         </style:style>
         <style:style style:name="'.$style_name.'_text_box" style:family="paragraph">
             <style:text-properties fo:color="'.$odt_fo.'"/>
             <style:paragraph-properties
              fo:margin-left="'.$padding_left.'pt" fo:margin-right="10pt" fo:text-indent="0cm"/>
         </style:style>';
        $this->autostyles[$style_name] = $style;

        // Group the frame so that they are stacked one on each other.
        $this->p_close();
        $this->doc .= '<text:p>';
        if ( $display == NULL ) {
            $this->doc .= '<draw:g>';
        } else {
            $this->doc .= '<draw:g draw:display="' . $display . '">';
        }

        // Draw a frame with the image in it, if required.
        // FIXME: delete this part if 'background-image' in graphic style is working.
        if ( $picture != NULL )
        {
            $this->doc .= '<draw:frame draw:style-name="'.$style_name.'_image_frame" draw:name="Bild1"
                                text:anchor-type="paragraph"
                                svg:x="'.$pic_positions [0].'" svg:y="'.$pic_positions [0].'"
                                svg:width="'.$pic_width.'" svg:height="'.$pic_height.'"
                                draw:z-index="'.($this->div_z_index + 1).'">
                               <draw:image xlink:href="'.$pic_link.'"
                                xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>
                                </draw:frame>';
        }

        // Draw a frame with a text box in it. the text box will be left opened
        // to grow with the content (requires fo:min-height in $style_name).
        $this->doc .= '<draw:frame draw:style-name="'.$style_name.'_text_frame" draw:name="Bild1"
                            text:anchor-type="paragraph"
                            svg:x="0cm" svg:y="0cm"
                            svg:width="'.$width_abs.'cm" svg:height="'.$min_height.'" ';
        $this->doc .= 'draw:z-index="'.($this->div_z_index + 0).'">';
        $this->doc .= '<draw:text-box ';

        // If required use round corners.
        if ( !empty($radius) )
            $this->doc .= 'draw:corner-radius="'.$radius.'" ';

        $this->doc .= '>';
        $this->p_open($style_name.'_text_box');
    }

    /**
     * This function opens a div. As divs are not supported by ODT, it will be exported as a frame.
     * To be more precise, to frames will be created. One including a picture nad the other including the text.
     * A picture frame will only be created if a 'background-image' is set in the CSS style.
     *
     * The currently supported CSS properties are:
     * background-color, color, padding, margin, display, border-radius, min-height.
     * The background-image is simulated using a picture frame.
     * FIXME: Find a way to successfuly use the background-image in the graphic style (see comments).
     *
     * The div should be closed by calling '_odtDivCloseAsFrame'.
     *
     * @author LarsDW223
     *
     * @param array $properties
     */
    function _odtDivOpenAsFrameUseProperties ($properties) {
        dbg_deprecated('_odtOpenTextBoxUseProperties');
        $this->_odtOpenTextBoxUseProperties ($properties);
    }

    /**
     * This function closes a div/frame (previously opened with _odtDivOpenAsFrameUseCSS).
     *
     * @author LarsDW223
     */
    function _odtDivCloseAsFrame () {
        $this->_odtCloseTextBox();
    }

    /**
     * This function opens a new table using the style as set in the imported CSS $import.
     * So, the function requires the helper class 'helper_plugin_odt_cssimport'.
     * The CSS style is selected by the element type 'td' and the specified classes in $classes.
     *
     * This function calls _odtTableOpenUseProperties. See the function description for supported properties.
     *
     * The table should be closed by calling 'table_close()'.
     *
     * @author LarsDW223
     *
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param null $baseURL
     * @param null $element
     * @param null $maxcols
     * @param null $numrows
     */
    function _odtTableOpenUseCSS(helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL, $maxcols = NULL, $numrows = NULL){
        $properties = array();
        if ( empty($element) ) {
            $element = 'table';
        }
        $this->_processCSSClass ($properties, $import, $classes, $baseURL, $element);
        $this->_odtTableOpenUseProperties($properties, $maxcols, $numrows);
    }

    /**
     * This function opens a new table using the style as specified in $style.
     *
     * This function calls _odtTableOpenUseProperties. See the function description for supported properties.
     *
     * The table should be closed by calling 'table_close()'.
     *
     * @author LarsDW223
     *
     * @param $style
     * @param null $baseURL
     * @param null $maxcols
     * @param null $numrows
     */
    function _odtTableOpenUseCSSStyle($style, $baseURL = NULL, $maxcols = NULL, $numrows = NULL){
        $properties = array();
        $this->_processCSSStyle ($properties, $style, $baseURL);
        $this->_odtTableOpenUseProperties($properties, $maxcols, $numrows);
    }

    /**
     * This function opens a new table using the style as set in the assoziative array $properties.
     * The parameters in the array should be named as the CSS property names e.g. 'width'.
     *
     * The currently supported properties are:
     * width, border-collapse, background-color
     *
     * The table must be closed by calling 'table_close'.
     *
     * @author LarsDW223
     *
     * @param array $properties
     * @param null $maxcols
     * @param null $numrows
     */
    function _odtTableOpenUseProperties ($properties, $maxcols = NULL, $numrows = NULL){
        unset ($this->temp_content);

        // We are starting a new table header declaration.
        // The flag will be set to false on opening the first table cell of the body.
        $this->temp_in_header = true;

        // Create style.
        $style_name = $this->factory->createTableTableStyle ($style, $properties, NULL, $this->_getAbsWidthMindMargins (100));
        $this->autostyles[$style_name] = $style;
        if ( empty ($properties ['width']) ) {
            // If the caller did not specify a table width, save the style name
            // to eventually later replace the table width set in createTableTableStyle()
            // with the sum of all column width (in _odtTableClose).
            $this->temp_table_style = $style_name;
        }

        // Open the table referencing our style.
        $this->doc .= '<table:table table:style-name="'.$style_name.'">';

        // Delete any old values in the temporary column styles array.
        for ( $column = 0 ; $column < count($this->temp_table_column_styles) ; $column++ ) {
            unset($this->temp_table_column_styles [$column]);
        }

        // Create columns with predefined and temporarily remembered style names.
        if ( empty ($maxcols) ) {
            // Try to automatically detect the number of columns.
            $this->temp_autocols = true;
            $this->doc .= '<ColumnsPlaceholder>';
            unset ($this->temp_cols);
        } else {
            $this->temp_autocols = false;

            for ( $column = 0 ; $column < $maxcols ; $column++ ) {
                $this->style_count++;
                $style_name = 'odt_auto_style_table_column_'.$this->style_count;
                $this->temp_table_column_styles [$column] = $style_name;

                $this->doc .= '<table:table-column table:style-name="'.$style_name.'"/>';
            }
        }

        // Reset temporary column counter
        $this->temp_column = 0;
        $this->temp_maxcols = 0;
    }

    protected function _replaceTableWidth () {
        $matches = array ();

        if ( empty($this->temp_table_style) || empty($this->temp_cols) ) {
            return;
        }

        // Search through all column styles for the column width ('style:width="..."').
        // If every column has a absolute width set, add them all and replace the table
        // with with the result.
        // Abort if a column has a percentage width or no width.
        $sum = 0;
        for ($index = 0 ; $index < $this->temp_maxcols ; $index++ ) {
            $style_name = $this->temp_table_column_styles [$index];
            $found = preg_match ('/style:column-width="[^"]*"/', $this->autostyles[$style_name], $matches);
            if ( $found != 1 ) {
                return;
            }
            $width = substr ($matches [0], strlen ('style:column-width='));
            $width = trim ($width, '"');
            $length = strlen ($width);
            if ( $width [$length-1] == '%' ) {
                return;
            }
            $width = $this->units->toPoints($width, 'x');
            $sum += (float) trim ($width, 'pt');
        }
        $this->autostyles[$this->temp_table_style] =
            preg_replace ('/style:width="[^"]*"/', 'style:width="'.$sum.'pt"', $this->autostyles[$this->temp_table_style]);
    }

    function _odtTableClose () {
        // Eventually replace table width.
        $this->_replaceTableWidth ();

        // Writeback temporary table content if this is the first cell in the table body.
        if ( !empty($this->temp_cols) ) {
            // First replace columns placeholder with created columns, if in auto mode.
            if ( $this->temp_autocols === true ) {
                $this->doc =
                    str_replace ('<ColumnsPlaceholder>', $this->temp_cols, $this->doc);

                $this->doc =
                    str_replace ('<MaxColsPlaceholder>', $this->temp_maxcols, $this->doc);

                unset ($this->temp_content);
                unset ($this->temp_cols);
                $this->temp_autocols = false;
            }
        }
        $this->doc .= '</table:table>';
    }

    /**
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param null $baseURL
     * @param null $element
     * @param int $colspan
     * @param int $rowspan
     */
    function _odtTableHeaderOpenUseCSS(helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL, $colspan = 1, $rowspan = 1){
        $properties = array();
        if ( empty($element) ) {
            $element = 'th';
        }
        $this->_processCSSClass ($properties, $import, $classes, $baseURL, $element);
        $this->_odtTableHeaderOpenUseProperties($properties, $colspan, $rowspan);
    }

    /**
     * @param $style
     * @param null $baseURL
     * @param int $colspan
     * @param int $rowspan
     */
    function _odtTableHeaderOpenUseCSSStyle($style, $baseURL = NULL, $colspan = 1, $rowspan = 1){
        $properties = array();
        $this->_processCSSStyle ($properties, $style, $baseURL);
        $this->_odtTableHeaderOpenUseProperties($properties, $colspan, $rowspan);
    }

    /**
     * @param array $properties
     */
    function _odtTableAddColumnUseProperties (array $properties = NULL){
        // Overwrite/Create column style for actual column if $properties has any
        // meaningful params for a column-style (e.g. width).
        $style_name = $this->temp_table_column_styles [$this->temp_column];
        $style_name = $this->factory->createTableColumnStyle ($style, $properties, NULL, $style_name);
        $this->temp_table_column_styles [$this->temp_column] = $style_name;
        $this->autostyles[$style_name] = $style;
        $this->temp_column++;

        // Eventually add a new temp column if in auto mode
        if ( $this->temp_autocols === true ) {
            if ( $this->temp_column > $this->temp_maxcols ) {
                // Add temp column.
                $this->temp_cols .= '<table:table-column table:style-name="'.$style_name.'"/>';
                $this->temp_maxcols++;
            }
        }
    }

    /**
     * @param null $properties
     * @param int $colspan
     * @param int $rowspan
     */
    function _odtTableHeaderOpenUseProperties ($properties = NULL, $colspan = 1, $rowspan = 1){
        // Open cell, second parameter MUST BE true to indicate we are in the header.
        $this->_odtTableCellOpenUsePropertiesInternal ($properties, true, $colspan, $rowspan);
    }

    /**
     * This function opens a new table row using the style as set in the imported CSS $import.
     * So, the function requires the helper class 'helper_plugin_odt_cssimport'.
     * The CSS style is selected by the element type 'td' and the specified classes in $classes.
     *
     * This function calls _odtTableRowOpenUseProperties. See the function description for supported properties.
     *
     * The row should be closed by calling 'tablerow_close()'.
     *
     * @author LarsDW223
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param null $baseURL
     * @param null $element
     */
    function _odtTableRowOpenUseCSS(helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL){
        $properties = array();
        if ( empty($element) ) {
            $element = 'tr';
        }
        $this->_processCSSClass ($properties, $import, $classes, $baseURL, $element);
        $this->_odtTableRowOpenUseProperties($properties);

        // Reset temporary column counter
        $this->temp_column = 0;
    }

    /**
     * This function opens a new table row using the style as specified in $style.
     *
     * This function calls _odtTableRowOpenUseProperties. See the function description for supported properties.
     *
     * The row should be closed by calling 'tablerow_close()'.
     *
     * @author LarsDW223
     *
     * @param $style
     * @param null $baseURL
     */
    function _odtTableRowOpenUseCSSStyle($style, $baseURL = NULL){
        $properties = array();
        $this->_processCSSStyle ($properties, $style, $baseURL);
        $this->_odtTableRowOpenUseProperties($properties);
    }

    /**
     * @param array $properties
     */
    function _odtTableRowOpenUseProperties ($properties){
        // Create style.
        $style_name = $this->factory->createTableRowStyle ($style, $properties);
        $this->autostyles[$style_name] = $style;

        // Open table row.
        $this->doc .= '<table:table-row table:style-name="'.$style_name.'">';
    }

    /**
     * This function opens a new table cell using the style as set in the imported CSS $import.
     * So, the function requires the helper class 'helper_plugin_odt_cssimport'.
     * The CSS style is selected by the element type 'td' and the specified classes in $classes.
     *
     * This function calls _odtTableCellOpenUseProperties. See the function description for supported properties.
     *
     * The cell should be closed by calling 'tablecell_close()'.
     *
     * @author LarsDW223
     *
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param null $baseURL
     * @param null $element
     */
    function _odtTableCellOpenUseCSS(helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL){
        $properties = array();
        if ( empty($element) ) {
            $element = 'td';
        }
        $this->_processCSSClass ($properties, $import, $classes, $baseURL, $element);
        $this->_odtTableCellOpenUseProperties($properties);
    }

    /**
     * This function opens a new table cell using the style as specified in $style.
     *
     * This function calls _odtTableCellOpenUseProperties. See the function description for supported properties.
     *
     * The cell should be closed by calling 'tablecell_close()'.
     *
     * @author LarsDW223
     *
     * @param $style
     * @param null $baseURL
     */
    function _odtTableCellOpenUseCSSStyle($style, $baseURL = NULL){
        $properties = array();
        $this->_processCSSStyle ($properties, $style, $baseURL);
        $this->_odtTableCellOpenUseProperties($properties);
    }

    /**
     * @param $properties
     */
    function _odtTableCellOpenUseProperties ($properties){
        $this->_odtTableCellOpenUsePropertiesInternal ($properties);
    }

    /**
     * @param $properties
     * @param bool $inHeader
     * @param int $colspan
     * @param int $rowspan
     */
    protected function _odtTableCellOpenUsePropertiesInternal ($properties, $inHeader = false, $colspan = 1, $rowspan = 1){
        $disabled = array ();

        if ( $inHeader === false ) {
            // Table header definition is finished.
            $this->temp_in_header = false;
        }

        // Create style name. (Re-enable background-color!)
        $style_name = $this->factory->createTableCellStyle ($style, $properties);
        $this->autostyles[$style_name] = $style;

        // Create a paragraph style for the paragraph within the cell.
        // Disable properties that belong to the table cell style.
        $disabled ['border'] = 1;
        $disabled ['border-left'] = 1;
        $disabled ['border-right'] = 1;
        $disabled ['border-top'] = 1;
        $disabled ['border-bottom'] = 1;
        $disabled ['background-color'] = 1;
        $disabled ['background-image'] = 1;
        $disabled ['vertical-align'] = 1;
        $style_name_paragraph = $this->_createParagraphStyle ($properties, $disabled);

        // Open cell.
        $this->doc .= '<table:table-cell office:value-type="string" table:style-name="'.$style_name.'" ';
        if ( $colspan > 1 ) {
            $this->doc .= ' table:number-columns-spanned="'.$colspan.'"';
        }
        if ( $inHeader === true && $this->temp_autocols === true && $colspan == 0 ) {
            $this->doc .= ' table:number-columns-spanned="<MaxColsPlaceholder>"';
        }
        if ( $rowspan > 1 ) {
            $this->doc .= ' table:number-rows-spanned="'.$rowspan.'"';
        }
        $this->doc .= '>';

        // If a paragraph style was created, means text properties were set,
        // then we open a paragraph with our own style. Otherwise we use the standard style.
        if ( $style_name_paragraph != NULL ) {
            $this->p_open($style_name_paragraph);
        } else {
            $this->p_open();
        }
    }

    /**
     * Simple helper function for creating a style $name setting the specfied font size $size.
     *
     * @author LarsDW223
     *
     * @param string $name
     * @param string $size
     * @return string
     */
    protected function _odtBuildSizeStyle ($name, $size) {
        $style = '<style:style style:name="'.$name.'" style:display-name="'.$name.'" style:family="text"><style:text-properties fo:font-size="'.$size.'" style:font-size-asian="'.$size.'" style:font-size-complex="'.$size.'"/></style:style>';
        return $style;
    }

    /**
     * This function creates a text style using the style as set in the assoziative array $properties.
     * The parameters in the array should be named as the CSS property names e.g. 'color' or 'background-color'.
     * Properties which shall not be used in the style can be disabled by setting the value in disabled_props
     * to 1 e.g. $disabled_props ['color'] = 1 would block the usage of the color property.
     *
     * The currently supported properties are:
     * background-color, color, font-style, font-weight, font-size, border, font-family, font-variant, letter-spacing,
     * vertical-align, background-image
     *
     * The function returns the name of the new style or NULL if all relevant properties are empty.
     *
     * @author LarsDW223
     *
     * @param array $properties
     * @param array $disabled_props
     * @return null|string
     */
    protected function _createTextStyle($properties, $disabled_props = NULL){
        $save = $disabled_props ['font-size'];

        $odt_fo_size = '';
        if ( empty ($disabled_props ['font-size']) ) {
            $odt_fo_size = $properties ['font-size'];
        }
        $parent = '';
        $length = strlen ($odt_fo_size);
        if ( $length > 0 && $odt_fo_size [$length-1] == '%' ) {
            // A font-size in percent is only supported in common style definitions, not in automatic
            // styles. Create a common style and set it as parent for this automatic style.
            $name = 'Size'.trim ($odt_fo_size, '%').'pc';
            $this->styles [$name] = $this->_odtBuildSizeStyle ($name, $odt_fo_size);
            $parent = $name;
        }

        $style_name = $this->factory->createTextStyle($style, $properties, $disabled_props, $parent);
        if ( $style_name == NULL ) {
            return NULL;
        }
        $this->autostyles[$style_name] = $style;

        $disabled_props ['font-size'] = $save;

        return $style_name;
    }

    /**
     * This function creates a paragraph style using. It uses the createParagraphStyle function
     * from the stylefactory helper class but takes care of the extra handling required for the
     * font-size attribute.
     *
     * The function returns the name of the new style or NULL if all relevant properties are empty.
     *
     * @author LarsDW223
     *
     * @param array $properties
     * @param array $disabled_props
     * @return string|null
     */
    protected function _createParagraphStyle($properties, $disabled_props = NULL){
        $save = $disabled_props ['font-size'];

        $odt_fo_size = '';
        if ( empty ($disabled_props ['font-size']) ) {
            $odt_fo_size = $properties ['font-size'];
        }
        $parent = '';
        $length = strlen ($odt_fo_size);
        if ( $length > 0 && $odt_fo_size [$length-1] == '%' ) {
            // A font-size in percent is only supported in common style definitions, not in automatic
            // styles. Create a common style and set it as parent for this automatic style.
            $name = 'Size'.trim ($odt_fo_size, '%').'pc';
            $this->styles [$name] = $this->_odtBuildSizeStyle ($name, $odt_fo_size);
            $parent = $name;
        }

        $length = strlen ($properties ['text-indent']);
        if ( $length > 0 && $properties ['text-indent'] [$length-1] == '%' ) {
            // Percentage value needs to be converted to absolute value.
            // ODT standard says that percentage value should work if used in a common style.
            // This did not work with LibreOffice 4.4.3.2.
            $value = trim ($properties ['text-indent'], '%');
            $properties ['text-indent'] = $this->_getAbsWidthMindMargins ($value).'cm';
        }

        $style_name = $this->factory->createParagraphStyle($style, $properties, $disabled_props, $parent);
        if ( $style_name == NULL ) {
            return NULL;
        }
        $this->autostyles[$style_name] = $style;

        $disabled_props ['font-size'] = $save;

        return $style_name;
    }

    /**
     * This function processes the CSS declarations in $style and saves them in $properties
     * as key - value pairs, e.g. $properties ['color'] = 'red'. It also adjusts the values
     * for the ODT format and changes URLs to local paths if required, using $baseURL).
     *
     * @author LarsDW223
     * @param array $properties
     * @param $style
     * @param null $baseURL
     */
    public function _processCSSStyle(&$properties, $style, $baseURL = NULL){
        if ( $this->import == NULL ) {
            $this->import = new helper_plugin_odt_cssimport ();
            if ( $this->import == NULL ) {
                // Failed to create helper. Can't proceed.
                return;
            }
        }

        // Create rule with selector '*' (doesn't matter) and declarations as set in $style
        $rule = new css_rule ('*', $style);
        $rule->getProperties ($properties);
        foreach ($properties as $property => $value) {
            $properties [$property] = $this->adjustValueForODT ($property, $value, 14);
        }

        if ( !empty ($properties ['background-image']) ) {
            if ( !empty ($baseURL) ) {
                // Replace 'url(...)' with $baseURL
                $properties ['background-image'] = $this->import->replaceURLPrefix ($properties ['background-image'], $baseURL);
            }
        }
    }

    /**
     * This function examines the CSS properties for $classes and $element based on the data
     * in $import and saves them in $properties as key - value pairs, e.g. $properties ['color'] = 'red'.
     * It also adjusts the values for the ODT format and changes URLs to local paths if required, using $baseURL).
     *
     * @author LarsDW223
     *
     * @param array $properties
     * @param helper_plugin_odt_cssimport $import
     * @param $classes
     * @param null $baseURL
     * @param null $element
     */
    public function _processCSSClass(&$properties, helper_plugin_odt_cssimport $import, $classes, $baseURL = NULL, $element = NULL){
        $import->getPropertiesForElement($properties, $element, $classes);
        foreach ($properties as $property => $value) {
            $properties [$property] = $this->adjustValueForODT ($property, $value, 14);
        }

        if ( !empty ($properties ['background-image']) ) {
            if ( !empty ($baseURL) ) {
                // Replace 'url(...)' with $baseURL
                $properties ['background-image'] = $import->replaceURLPrefix ($properties ['background-image'], $baseURL);
            }
        }
    }

    /**
     * This function opens a multi column frame according to the parameters in $properties.
     * See function createMultiColumnFrameStyle of helper class stylefactory.php for more
     * information about the supported properties/CSS styles.
     *
     * @author LarsDW223
     *
     * @param $properties
     */
    function _odtOpenMultiColumnFrame ($properties) {
        // Create style name.
        $style_name = $this->factory->createMultiColumnFrameStyle ($style, $properties);
        $this->autostyles[$style_name] = $style;

        $width_abs = $this->_getAbsWidthMindMargins (100);

        // Group the frame so that they are stacked one on each other.
        $this->p_close();
        $this->doc .= '<text:p>';

        // Draw a frame with a text box in it. the text box will be left opened
        // to grow with the content (requires fo:min-height in $style_name).
        $this->doc .= '<draw:frame draw:style-name="'.$style_name.'" draw:name="Frame1"
                        text:anchor-type="paragraph" svg:width="'.$width_abs.'cm" draw:z-index="0">';
        $this->doc .= '<draw:text-box fo:min-height="1pt">';
    }

    /**
     * This function closes a multi column frame (previously opened with _odtOpenMultiColumnFrame).
     *
     * @author LarsDW223
     */
    function _odtCloseMultiColumnFrame () {
        $this->doc .= '</draw:text-box></draw:frame>';
        $this->doc .= '</text:p>';

        $this->div_z_index -= 5;
    }

    /**
     * This function opens a textbox in a frame.
     *
     * The currently supported CSS properties are:
     * background-color, color, padding, margin, display, border-radius, min-height.
     * The background-image is simulated using a picture frame.
     * FIXME: Find a way to successfuly use the background-image in the graphic style (see comments).
     *
     * The div should be closed by calling '_odtDivCloseAsFrame'.
     *
     * @author LarsDW223
     *
     * @param array $properties
     */
    function _odtOpenTextBoxUseProperties ($properties) {
        $this->in_div_as_frame++;
        if ( $this->in_div_as_frame > 1 ) {
            // Do not open a nested frame as this will make the content ofthe nested frame disappear.
            //return;
        }

        $this->div_z_index += 5;
        $this->style_count++;

        $odt_bg = $properties ['background-color'];
        $odt_fo = $properties ['color'];
        $padding_left = $properties ['padding-left'];
        $padding_right = $properties ['padding-right'];
        $padding_top = $properties ['padding-top'];
        $padding_bottom = $properties ['padding-bottom'];
        $margin_left = $properties ['margin-left'];
        $margin_right = $properties ['margin-right'];
        $margin_top = $properties ['margin-top'];
        $margin_bottom = $properties ['margin-bottom'];
        $display = $properties ['display'];
        $fo_border = $properties ['border'];
        $border_color = $properties ['border-color'];
        $border_width = $properties ['border-width'];
        $radius = $properties ['border-radius'];
        $picture = $properties ['background-image'];
        $pic_positions = preg_split ('/\s/', $properties ['background-position']);

        $min_height = $properties ['min-height'];
        $width = $properties ['width'];
        $horiz_pos = $properties ['float'];

        $pic_link = '';
        $pic_width = '';
        $pic_height = '';
        if ( !empty ($picture) ) {
            // If a picture/background-image is set in the CSS, than we insert it manually here.
            // This is a workaround because ODT does not support the background-image attribute in a span.
            $pic_link=$this->_odtAddImageAsFileOnly($picture);
            list($pic_width, $pic_height) = $this->_odtGetImageSize($picture);
        }

        if ( empty($horiz_pos) ) {
            $horiz_pos = 'center';
        }
        if ( empty ($width) ) {
            $width = '100%';
        }
        if ( empty($border_color) ) {
            $border_color = $odt_bg;
        }
        if ( !empty($pic_positions [0]) ) {
            $pic_positions [0] = $this->adjustXLengthValueForODT ($pic_positions [0]);
        }
        if ( empty($min_height) ) {
            $min_height = '1pt';
        }

        // Different handling for relative and absolute size...
        if ( $width [strlen($width)-1] == '%' ) {
            // Convert percentage values to absolute size, respecting page margins
            $width = trim($width, '%');
            $width_abs = $this->_getAbsWidthMindMargins ($width).'cm';
        } else {
            // Absolute values may include not supported units.
            // Adjust.
            $width_abs = $this->adjustXLengthValueForODT($width);
        }


        // Add our styles.
        $style_name = 'odt_auto_style_div_'.$this->style_count;

        $style =
         '<style:style style:name="'.$style_name.'_text_frame" style:family="graphic">
             <style:graphic-properties
                 draw:textarea-horizontal-align="left"
                 style:horizontal-pos="'.$horiz_pos.'" fo:background-color="'.$odt_bg.'" style:background-transparency="100%" ';
        if ( !empty($odt_bg) ) {
            $style .= 'draw:fill="solid" draw:fill-color="'.$odt_bg.'" ';
        } else {
            $style .= 'draw:fill="none" ';
        }
        if ( !empty($border_color) ) {
            $style .= 'svg:stroke-color="'.$border_color.'" ';
        }
        if ( !empty($border_width) ) {
            $style .= 'svg:stroke-width="'.$border_width.'" ';
        }
        if ( !empty($padding_left) ) {
            $style .= 'fo:padding-left="'.$padding_left.'" ';
        }
        if ( !empty($padding_right) ) {
            $style .= 'fo:padding-right="'.$padding_right.'" ';
        }
        if ( !empty($padding_top) ) {
            $style .= 'fo:padding-top="'.$padding_top.'" ';
        }
        if ( !empty($padding_bottom) ) {
            $style .= 'fo:padding-bottom="'.$padding_bottom.'" ';
        }
        if ( !empty($margin_left) ) {
            $style .= 'fo:margin-left="'.$margin_left.'" ';
        }
        if ( !empty($margin_right) ) {
            $style .= 'fo:margin-right="'.$margin_right.'" ';
        }
        if ( !empty($margin_top) ) {
            $style .= 'fo:margin-top="'.$margin_top.'" ';
        }
        if ( !empty($margin_bottom) ) {
            $style .= 'fo:margin-bottom="'.$margin_bottom.'" ';
        }
        if ( !empty ($fo_border) ) {
            $style .= 'fo:border="'.$fo_border.'" ';
        }
        $style .= 'fo:min-height="'.$min_height.'"
                 style:wrap="none"';
        $style .= '>';

        // FIXME: Delete the part below 'if ( $picture != NULL ) {...}'
        // and use this background-image definition. For some reason the background-image is not displayed.
        // Help is welcome.
        /*$style .= '<style:background-image ';
        $style .= 'xlink:href="'.$pic_link.'" xlink:type="simple" xlink:actuate="onLoad"
                   style:position="center center" style:repeat="no-repeat" draw:opacity="100%"/>';*/
        $style .= '</style:graphic-properties>';
        $style .= '</style:style>';
        $style .= '<style:style style:name="'.$style_name.'_image_frame" style:family="graphic">
             <style:graphic-properties svg:stroke-color="'.$odt_bg.'"
                 draw:fill="none" draw:fill-color="'.$odt_bg.'"
                 draw:textarea-horizontal-align="left"
                 draw:textarea-vertical-align="center"
                 style:wrap="none"/>
         </style:style>
         <style:style style:name="'.$style_name.'_text_box" style:family="paragraph">
             <style:text-properties fo:color="'.$odt_fo.'"/>
             <style:paragraph-properties
              fo:margin-left="'.$padding_left.'" fo:margin-right="10pt" fo:text-indent="0cm"/>
         </style:style>';
        $this->autostyles[$style_name] = $style;

        // Group the frame so that they are stacked one on each other.
        $this->p_close();
        $this->doc .= '<text:p>';
        $this->linebreak();
        if ( $display == NULL ) {
            $this->doc .= '<draw:g>';
        } else {
            $this->doc .= '<draw:g draw:display="' . $display . '">';
        }

        $anchor_type = 'paragraph';
        // FIXME: Later try to get nested frames working - probably with anchor = as-char
        if ( $this->in_div_as_frame > 1 ) {
            $anchor_type = 'as-char';
        }

        // Draw a frame with the image in it, if required.
        // FIXME: delete this part if 'background-image' in graphic style is working.
        if ( $picture != NULL )
        {
            $this->doc .= '<draw:frame draw:style-name="'.$style_name.'_image_frame" draw:name="Bild1"
                                text:anchor-type="paragraph"
                                svg:x="'.$pic_positions [0].'" svg:y="'.$pic_positions [0].'"
                                svg:width="'.$pic_width.'" svg:height="'.$pic_height.'"
                                draw:z-index="'.($this->div_z_index + 1).'">
                               <draw:image xlink:href="'.$pic_link.'"
                                xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>
                                </draw:frame>';
        }

        // Draw a frame with a text box in it. the text box will be left opened
        // to grow with the content (requires fo:min-height in $style_name).
        $this->doc .= '<draw:frame draw:style-name="'.$style_name.'_text_frame" draw:name="Bild1"
                            text:anchor-type="'.$anchor_type.'"
                            svg:x="0cm" svg:y="0cm"
                            svg:width="'.$width_abs.'" svg:height="'.$min_height.'" ';
        $this->doc .= 'draw:z-index="'.($this->div_z_index + 0).'">';
        $this->doc .= '<draw:text-box ';

        // If required use round corners.
        if ( !empty($radius) )
            $this->doc .= 'draw:corner-radius="'.$radius.'" ';

        $this->doc .= '>';
        //$this->p_open($style_name.'_text_box');
    }

    /**
     * This function closes a textbox (previously opened with _odtOpenTextBoxUseProperties).
     *
     * @author LarsDW223
     */
    function _odtCloseTextBox () {
        if ( $this->in_div_as_frame > 0 ) {
            $this->in_div_as_frame--;
        }
        if ( $this->in_div_as_frame > 0 ) {
            // Do not close the frame if this is a close for a nested frame.
            //return;
        }

        //$this->p_close();
        $this->doc .= '</draw:text-box></draw:frame>';
        $this->doc .= '</draw:g>';
        $this->doc .= '</text:p>';

        $this->div_z_index -= 5;
    }

    /**
     * @param array $dest
     * @param $element
     * @param $classString
     * @param $inlineStyle
     */
    public function getCSSProperties (&$dest, $element, $classString, $inlineStyle) {
        // Get properties for our class/element from imported CSS
        $this->import->getPropertiesForElement($dest, $element, $classString);

        // Interpret and add values from style to our properties
        $this->_processCSSStyle($dest, $inlineStyle);
    }

    /**
     * @param array $dest
     * @param $element
     * @param $classString
     * @param $inlineStyle
     */
    public function getODTProperties (&$dest, $element, $classString, $inlineStyle) {
        // Get properties for our class/element from imported CSS
        $this->import->getPropertiesForElement($dest, $element, $classString, $this->config->getParam ('media_sel'));

        // Interpret and add values from style to our properties
        $this->_processCSSStyle($dest, $inlineStyle);

        // Adjust values for ODT
        foreach ($dest as $property => $value) {
            $dest [$property] = $this->adjustValueForODT ($property, $value, 14);
        }
    }

    /**
     * @param $URL
     * @param $replacement
     * @return string
     */
    public function replaceURLPrefix ($URL, $replacement) {
        return $this->import->replaceURLPrefix ($URL, $replacement);
    }

    /**
     * @param $pixel
     * @return float
     */
    public function pixelToPointsX ($pixel) {
        return ($pixel * $this->config->getParam ('twips_per_pixel_x')) / 20;
    }

    /**
     * @param $pixel
     * @return float
     */
    public function pixelToPointsY ($pixel) {
        return ($pixel * $this->config->getParam ('twips_per_pixel_y')) / 20;
    }

    /**
     * @param $property
     * @param $value
     * @param int $emValue
     * @return string
     */
    public function adjustValueForODT ($property, $value, $emValue = 0) {
        $values = preg_split ('/\s+/', $value);
        $value = '';
        foreach ($values as $part) {
            $length = strlen ($part);

            // If it is a short color value (#xxx) then convert it to long value (#xxxxxx)
            // (ODT does not support the short form)
            if ( $part [0] == '#' && $length == 4 ) {
                $part = '#'.$part [1].$part [1].$part [2].$part [2].$part [3].$part [3];
            } else {
                // If it is a CSS color name, get it's real color value
                /** @var helper_plugin_odt_csscolors $odt_colors */
                $odt_colors = plugin_load('helper', 'odt_csscolors');
                $color = $odt_colors->getColorValue ($part);
                if ( $part == 'black' || $color != '#000000' ) {
                    $part = $color;
                }
            }

            if ( $length > 2 && $part [$length-2] == 'e' && $part [$length-1] == 'm' ) {
                $number = substr ($part, 0, $length-2);
                if ( is_numeric ($number) && !empty ($emValue) ) {
                    $part = ($number * $emValue).'pt';
                }
            }

            $value .= ' '.$part;
        }
        $value = trim($value);

        return $value;
    }

    /**
     * This function adjust the length string $value for ODT and returns the adjusted string:
     * - If there are only digits in the string, the unit 'pt' is appended
     * - If the unit is 'px' it is replaced by 'pt'
     *   (the OpenDocument specification only optionally supports 'px' and it seems that at
     *   least LibreOffice is not supporting it)
     *
     * @author LarsDW223
     *
     * @param string|int|float $value
     * @return string
     */
    function adjustLengthValueForODT ($value) {
        dbg_deprecated('_odtOpenTextBoxUseProperties');

        // If there are only digits, append 'pt' to it
        if ( ctype_digit($value) === true ) {
            $value = $value.'pt';
        } else {
            // Replace px with pt (px does not seem to be supported by ODT)
            $length = strlen ($value);
            if ( $length > 2 && $value [$length-2] == 'p' && $value [$length-1] == 'x' ) {
                $value [$length-1] = 't';
            }
        }
        return $value;
    }

    /**
     * This function adjust the length string $value for ODT and returns the adjusted string:
     * If no unit or pixel are specified, then pixel are converted to points using the
     * configured twips per point (X axis).
     *
     * @author LarsDW223
     *
     * @param $value
     * @return string
     */
    function adjustXLengthValueForODT ($value) {
        // If there are only digits or if the unit is pixel,
        // convert from pixel to point.
        if ( ctype_digit($value) === true ) {
            $value = $this->pixelToPointsX($value).'pt';
        } else {
            $length = strlen ($value);
            if ( $length > 2 && $value [$length-2] == 'p' && $value [$length-1] == 'x' ) {
                $number = trim($value, 'px');
                $value = $this->pixelToPointsX($number).'pt';
            }
        }
        return $value;
    }

    /**
     * This function adjust the length string $value for ODT and returns the adjusted string:
     * If no unit or pixel are specified, then pixel are converted to points using the
     * configured twips per point (Y axis).
     *
     * @author LarsDW223
     *
     * @param $value
     * @return string
     */
    function adjustYLengthValueForODT ($value) {
        // If there are only digits or if the unit is pixel,
        // convert from pixel to point.
        if ( ctype_digit($value) === true ) {
            $value = $this->pixelToPointsY($value).'pt';
        } else {
            $length = strlen ($value);
            if ( $length > 2 && $value [$length-2] == 'p' && $value [$length-1] == 'x' ) {
                $number = trim($value, 'px');
                $value = $this->pixelToPointsY($number).'pt';
            }
        }
        return $value;
    }

    /**
     * @param $property
     * @param $value
     * @param $type
     * @return string
     */
    public function adjustLengthCallback ($property, $value, $type) {
        // Replace px with pt (px does not seem to be supported by ODT)
        $length = strlen ($value);
        if ( $length > 2 && $value [$length-2] == 'p' && $value [$length-1] == 'x' ) {
            $number = trim($value, 'px');
            switch ($type) {
                case CSSValueType::LengthValueXAxis:
                    $adjusted = $this->pixelToPointsX($number).'pt';
                break;

                case CSSValueType::StrokeOrBorderWidth:
                    switch ($property) {
                        case 'border':
                        case 'border-left':
                        case 'border-right':
                        case 'border-top':
                        case 'border-bottom':
                            // border in ODT spans does not support 'px' units, so we convert it.
                            $adjusted = $this->pixelToPointsY($number).'pt';
                        break;

                        default:
                            $adjusted = $value;
                        break;
                    }
                break;

                case CSSValueType::LengthValueYAxis:
                default:
                    $adjusted = $this->pixelToPointsY($number).'pt';
                break;
            }
            // Only for debugging.
            //$this->trace_dump .= 'adjustLengthCallback: '.$property.':'.$value.'==>'.$adjusted.'<text:line-break/>';
            return $adjusted;
        }
        // Only for debugging.
        //$this->trace_dump .= 'adjustLengthCallback: '.$property.':'.$value.'<text:line-break/>';
        return $value;
    }


}

//Setup VIM: ex: et ts=4 enc=utf-8 :
