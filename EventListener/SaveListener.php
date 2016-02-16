<?php

namespace Enhavo\Bundle\SearchBundle\EventListener;

use Enhavo\Bundle\SearchBundle\Entity\Dataset;
use Enhavo\Bundle\SearchBundle\Entity\Index;
use Enhavo\Bundle\SearchBundle\Entity\Total;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Parser;
use Doctrine\ORM\EntityManager;

/**
 * Matches all 'N' Unicode character classes (numbers)
 */
define('PREG_CLASS_NUMBERS',
    '\x{30}-\x{39}\x{b2}\x{b3}\x{b9}\x{bc}-\x{be}\x{660}-\x{669}\x{6f0}-\x{6f9}' .
    '\x{966}-\x{96f}\x{9e6}-\x{9ef}\x{9f4}-\x{9f9}\x{a66}-\x{a6f}\x{ae6}-\x{aef}' .
    '\x{b66}-\x{b6f}\x{be7}-\x{bf2}\x{c66}-\x{c6f}\x{ce6}-\x{cef}\x{d66}-\x{d6f}' .
    '\x{e50}-\x{e59}\x{ed0}-\x{ed9}\x{f20}-\x{f33}\x{1040}-\x{1049}\x{1369}-' .
    '\x{137c}\x{16ee}-\x{16f0}\x{17e0}-\x{17e9}\x{17f0}-\x{17f9}\x{1810}-\x{1819}' .
    '\x{1946}-\x{194f}\x{2070}\x{2074}-\x{2079}\x{2080}-\x{2089}\x{2153}-\x{2183}' .
    '\x{2460}-\x{249b}\x{24ea}-\x{24ff}\x{2776}-\x{2793}\x{3007}\x{3021}-\x{3029}' .
    '\x{3038}-\x{303a}\x{3192}-\x{3195}\x{3220}-\x{3229}\x{3251}-\x{325f}\x{3280}-' .
    '\x{3289}\x{32b1}-\x{32bf}\x{ff10}-\x{ff19}');

/**
 * Matches all 'P' Unicode character classes (punctuation)
 */
define('PREG_CLASS_PUNCTUATION',
    '\x{21}-\x{23}\x{25}-\x{2a}\x{2c}-\x{2f}\x{3a}\x{3b}\x{3f}\x{40}\x{5b}-\x{5d}' .
    '\x{5f}\x{7b}\x{7d}\x{a1}\x{ab}\x{b7}\x{bb}\x{bf}\x{37e}\x{387}\x{55a}-\x{55f}' .
    '\x{589}\x{58a}\x{5be}\x{5c0}\x{5c3}\x{5f3}\x{5f4}\x{60c}\x{60d}\x{61b}\x{61f}' .
    '\x{66a}-\x{66d}\x{6d4}Unic\x{700}-\x{70d}\x{964}\x{965}\x{970}\x{df4}\x{e4f}' .
    '\x{e5a}\x{e5b}\x{f04}-\x{f12}\x{f3a}-\x{f3d}\x{f85}\x{104a}-\x{104f}\x{10fb}' .
    '\x{1361}-\x{1368}\x{166d}\x{166e}\x{169b}\x{169c}\x{16eb}-\x{16ed}\x{1735}' .
    '\x{1736}\x{17d4}-\x{17d6}\x{17d8}-\x{17da}\x{1800}-\x{180a}\x{1944}\x{1945}' .
    '\x{2010}-\x{2027}\x{2030}-\x{2043}\x{2045}-\x{2051}\x{2053}\x{2054}\x{2057}' .
    '\x{207d}\x{207e}\x{208d}\x{208e}\x{2329}\x{232a}\x{23b4}-\x{23b6}\x{2768}-' .
    '\x{2775}\x{27e6}-\x{27eb}\x{2983}-\x{2998}\x{29d8}-\x{29db}\x{29fc}\x{29fd}' .
    '\x{3001}-\x{3003}\x{3008}-\x{3011}\x{3014}-\x{301f}\x{3030}\x{303d}\x{30a0}' .
    '\x{30fb}\x{fd3e}\x{fd3f}\x{fe30}-\x{fe52}\x{fe54}-\x{fe61}\x{fe63}\x{fe68}' .
    '\x{fe6a}\x{fe6b}\x{ff01}-\x{ff03}\x{ff05}-\x{ff0a}\x{ff0c}-\x{ff0f}\x{ff1a}' .
    '\x{ff1b}\x{ff1f}\x{ff20}\x{ff3b}-\x{ff3d}\x{ff3f}\x{ff5b}\x{ff5d}\x{ff5f}-' .
    '\x{ff65}');

class SaveListener
{
    /**
     * @var Container
     */
    protected $container;
    protected $path;
    protected $requestStack;
    protected $em;
    protected $dirty = array();

    /**
     * Matches Unicode characters that are word boundaries.
     *
     * Characters with the following General_category (gc) property values are used
     * as word boundaries. While this does not fully conform to the Word Boundaries
     * algorithm described in http://unicode.org/reports/tr29, as PCRE does not
     * contain the Word_Break property table, this simpler algorithm has to do.
     * - Cc, Cf, Cn, Co, Cs: Other.
     * - Pc, Pd, Pe, Pf, Pi, Po, Ps: Punctuation.
     * - Sc, Sk, Sm, So: Symbols.
     * - Zl, Zp, Zs: Separators.
     *
     * Non-boundary characters include the following General_category (gc) property
     * values:
     * - Ll, Lm, Lo, Lt, Lu: Letters.
     * - Mc, Me, Mn: Combining Marks.
     * - Nd, Nl, No: Numbers.
     *
     * Note that the PCRE property matcher is not used because we wanted to be
     * compatible with Unicode 5.2.0 regardless of the PCRE version used (and any
     * bugs in PCRE property tables).
     *
     * @see http://unicode.org/glossary
     */
    const PREG_CLASS_WORD_BOUNDARY = <<<'EOD'
\x{0}-\x{2F}\x{3A}-\x{40}\x{5B}-\x{60}\x{7B}-\x{A9}\x{AB}-\x{B1}\x{B4}
\x{B6}-\x{B8}\x{BB}\x{BF}\x{D7}\x{F7}\x{2C2}-\x{2C5}\x{2D2}-\x{2DF}
\x{2E5}-\x{2EB}\x{2ED}\x{2EF}-\x{2FF}\x{375}\x{37E}-\x{385}\x{387}\x{3F6}
\x{482}\x{55A}-\x{55F}\x{589}-\x{58A}\x{5BE}\x{5C0}\x{5C3}\x{5C6}
\x{5F3}-\x{60F}\x{61B}-\x{61F}\x{66A}-\x{66D}\x{6D4}\x{6DD}\x{6E9}
\x{6FD}-\x{6FE}\x{700}-\x{70F}\x{7F6}-\x{7F9}\x{830}-\x{83E}
\x{964}-\x{965}\x{970}\x{9F2}-\x{9F3}\x{9FA}-\x{9FB}\x{AF1}\x{B70}
\x{BF3}-\x{BFA}\x{C7F}\x{CF1}-\x{CF2}\x{D79}\x{DF4}\x{E3F}\x{E4F}
\x{E5A}-\x{E5B}\x{F01}-\x{F17}\x{F1A}-\x{F1F}\x{F34}\x{F36}\x{F38}
\x{F3A}-\x{F3D}\x{F85}\x{FBE}-\x{FC5}\x{FC7}-\x{FD8}\x{104A}-\x{104F}
\x{109E}-\x{109F}\x{10FB}\x{1360}-\x{1368}\x{1390}-\x{1399}\x{1400}
\x{166D}-\x{166E}\x{1680}\x{169B}-\x{169C}\x{16EB}-\x{16ED}
\x{1735}-\x{1736}\x{17B4}-\x{17B5}\x{17D4}-\x{17D6}\x{17D8}-\x{17DB}
\x{1800}-\x{180A}\x{180E}\x{1940}-\x{1945}\x{19DE}-\x{19FF}
\x{1A1E}-\x{1A1F}\x{1AA0}-\x{1AA6}\x{1AA8}-\x{1AAD}\x{1B5A}-\x{1B6A}
\x{1B74}-\x{1B7C}\x{1C3B}-\x{1C3F}\x{1C7E}-\x{1C7F}\x{1CD3}\x{1FBD}
\x{1FBF}-\x{1FC1}\x{1FCD}-\x{1FCF}\x{1FDD}-\x{1FDF}\x{1FED}-\x{1FEF}
\x{1FFD}-\x{206F}\x{207A}-\x{207E}\x{208A}-\x{208E}\x{20A0}-\x{20B8}
\x{2100}-\x{2101}\x{2103}-\x{2106}\x{2108}-\x{2109}\x{2114}
\x{2116}-\x{2118}\x{211E}-\x{2123}\x{2125}\x{2127}\x{2129}\x{212E}
\x{213A}-\x{213B}\x{2140}-\x{2144}\x{214A}-\x{214D}\x{214F}
\x{2190}-\x{244A}\x{249C}-\x{24E9}\x{2500}-\x{2775}\x{2794}-\x{2B59}
\x{2CE5}-\x{2CEA}\x{2CF9}-\x{2CFC}\x{2CFE}-\x{2CFF}\x{2E00}-\x{2E2E}
\x{2E30}-\x{3004}\x{3008}-\x{3020}\x{3030}\x{3036}-\x{3037}
\x{303D}-\x{303F}\x{309B}-\x{309C}\x{30A0}\x{30FB}\x{3190}-\x{3191}
\x{3196}-\x{319F}\x{31C0}-\x{31E3}\x{3200}-\x{321E}\x{322A}-\x{3250}
\x{3260}-\x{327F}\x{328A}-\x{32B0}\x{32C0}-\x{33FF}\x{4DC0}-\x{4DFF}
\x{A490}-\x{A4C6}\x{A4FE}-\x{A4FF}\x{A60D}-\x{A60F}\x{A673}\x{A67E}
\x{A6F2}-\x{A716}\x{A720}-\x{A721}\x{A789}-\x{A78A}\x{A828}-\x{A82B}
\x{A836}-\x{A839}\x{A874}-\x{A877}\x{A8CE}-\x{A8CF}\x{A8F8}-\x{A8FA}
\x{A92E}-\x{A92F}\x{A95F}\x{A9C1}-\x{A9CD}\x{A9DE}-\x{A9DF}
\x{AA5C}-\x{AA5F}\x{AA77}-\x{AA79}\x{AADE}-\x{AADF}\x{ABEB}
\x{E000}-\x{F8FF}\x{FB29}\x{FD3E}-\x{FD3F}\x{FDFC}-\x{FDFD}
\x{FE10}-\x{FE19}\x{FE30}-\x{FE6B}\x{FEFF}-\x{FF0F}\x{FF1A}-\x{FF20}
\x{FF3B}-\x{FF40}\x{FF5B}-\x{FF65}\x{FFE0}-\x{FFFD}
EOD;

    public function __construct(Container $container, $path, RequestStack $requestStack, EntityManager $em)
    {
        $this->container = $container;
        $this->path = $path;
        $this->requestStack = $requestStack;
        $this->em = $em;
    }

    public function onSave($event)
    {
        //search.yml der Entity auslesen um die zu indexierenden Felder zu finden
        $mainPath = str_replace('/app', '/src', $this->path);
        $entityPath = get_class($event->getSubject());
        $splittedEntityPath = explode("\\", $entityPath);
        $searchYamlPath = $mainPath;
        $i = 0;
        while($splittedEntityPath[$i] != 'Entity') {
            $searchYamlPath = $searchYamlPath.'/'.$splittedEntityPath[$i];
            $i++;
        }
        $searchYamlPath = $searchYamlPath.'/Resources/config/search.yml';
        $entityName = $splittedEntityPath[$i+1];
        $yaml = new Parser();
        $currentSearchYaml = $yaml->parse(file_get_contents($searchYamlPath));

        //Properties auslesen und diejenigen Felder indexieren
        $properties = $currentSearchYaml[$entityPath]['properties'];

        //1.data_set anlegen wenn nicht schon vorhanden
        $dataSetRepository = $this->em->getRepository('EnhavoSearchBundle:Dataset');
        $dataSet = $dataSetRepository->findOneBy(array('reference' => $event->getSubject()->getId()));
        if($dataSet == null) {
            $newDataSet = new Dataset();
            $newDataSet->setType(strtolower($entityName));
            $newDataSet->setReindex(0);
            $newDataSet->setReference($event->getSubject()->getId());
            $this->em->persist($newDataSet);
            $this->em->flush();
            $dataSet = $newDataSet;
        } else {
            $indexRepository = $this->em->getRepository('EnhavoSearchBundle:Index');
            $wordsForDataset = $indexRepository->findBy(array('dataset' => $dataSet));
            foreach($wordsForDataset as $word){
                $this->em->remove($word);
            }
            $this->em->flush();
        }

        //2.einzelne Wörter indexieren
        foreach($properties as $key => $value) {
            $indexingField = $key;

            $currentRequestName = $this->requestStack->getCurrentRequest()->request->keys();
            $currentRequest = $this->requestStack->getCurrentRequest()->request->get($currentRequestName[0]);

            if(array_key_exists($indexingField, $currentRequest)) {
                $text = $currentRequest[$indexingField];
                foreach ($value[0] as $key => $value) {
                    if($key == 'Plain') {
                        $this->indexingPlain($text, $value['weight'], $value['type'], $dataSet);
                    } else if($key == 'Html'){
                        if(array_key_exists('weights', $value)) {
                            $this->indexingHtml($text, $value['type'], $dataSet, $mainPath, $value['weights']);
                        } else {
                            $this->indexingHtml($text, $value['type'], $dataSet, $mainPath);
                        }
                    } else if($key == 'Collection') {
                        $collectionPath = $mainPath;
                        $entityPath = $value['entity'];
                        $splittedEntityPath = explode("\\", $entityPath);
                        $i = 0;
                        while($splittedEntityPath[$i] != 'Entity') {
                            $collectionPath = $collectionPath.'/'.$splittedEntityPath[$i];
                            $i++;
                        }
                        $collectionPath = $collectionPath.'/Resources/config/search.yml';
                        $yaml = new Parser();
                        $currentCollectionSearchYaml = $yaml->parse(file_get_contents($collectionPath));
                        $this->indexingCollection($text, $value['entity'], $currentCollectionSearchYaml, $mainPath, $dataSet);
                    }
                }
            }
        }
        $this->search_update_totals();
    }

    public function indexingPlain($text, $score, $type, $dataset) {
        //Text auseinander nehmen und in DB speichern (mit Hilfe von Repository(siehe im controller))
        $minimum_word_size = 2;
        $words = $this->search_index_split($text);
        $scored_words = array();
        $focus = 1;
        foreach($words as $word) {
            if (is_numeric($word) || strlen($word) >= $minimum_word_size) {
                $newWord = false;
                if (!isset($scored_words[$word])) {
                    $scored_words[$word] = 0;
                    $newWord = true;
                }
                $scored_words[$word] += $score * $focus;
                $focus = min(1, .01 + 3.5 / (2 + count($scored_words) * .015));
            }
        }
        foreach ($scored_words as $key => $value) {
            $newIndex = new Index();
            $newIndex->setDataset($dataset);
            $newIndex->setType(strtolower($type));
            $newIndex->setWord($key);
            $newIndex->setLocale($this->container->getParameter('locale'));
            $newIndex->setScore($value);
            $this->em->persist($newIndex);
            $this->em->flush();
            $this->search_dirty($key);
        }

    }

    public function indexingHtml($text, $type, $dataset, $mainPath, $weights = null) {
        $minimum_word_size = 2;

        //Default Werte der tags holen
        $tagYaml = $mainPath.'/Enhavo/Bundle/SearchBundle/Resources/config/tag_weights.yml';
        $yaml = new Parser();
        $tags = $yaml->parse(file_get_contents($tagYaml));
        if($weights != null) //Evtl. Weights einsetzen
        {
            foreach ($weights as $key => $value) {
                if(array_key_exists($key, $tags)) {
                    $tags[$key] = $value;
                } else {
                    $tags[$key] = $value;
                }
            }
        }

        // Strip off all ignored tags to speed up processing, but insert space before
        // and after them to keep word boundaries.
        $text = str_replace(array('<', '>'), array(' <', '> '), $text);
        $text = strip_tags($text, '<' . implode('><', array_keys($tags)) . '>');

        // Split HTML tags from plain text.
        $split = preg_split('/\s*<([^>]+?)>\s*/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $tag = FALSE; // Odd/even counter. Tag or no tag.
        $score = 1; // Starting score per word
        $accum = ' '; // Accumulator for cleaned up data
        $tagstack = array(); // Stack with open tags
        $tagwords = 0; // Counter for consecutive words
        $focus = 1; // Focus state

        foreach ($split as $value) {
            if ($tag) {
                // Increase or decrease score per word based on tag
                list($tagname) = explode(' ', $value, 2);
                $tagname = strtolower($tagname);
                // Closing or opening tag?
                if ($tagname[0] == '/') {
                    $tagname = substr($tagname, 1);
                    // If we encounter unexpected tags, reset score to avoid incorrect boosting.
                    if (!count($tagstack) || $tagstack[0] != $tagname) {
                        $tagstack = array();
                        $score = 1;
                    }
                    else {
                        // Remove from tag stack and decrement score
                        $score = max(1, $score - $tags[array_shift($tagstack)]);
                    }
                }
                else {
                    if (isset($tagstack[0]) && $tagstack[0] == $tagname) {
                        // None of the tags we look for make sense when nested identically.
                        // If they are, it's probably broken HTML.
                        $tagstack = array();
                        $score = 1;
                    }
                    else {
                        // Add to open tag stack and increment score
                        array_unshift($tagstack, $tagname);
                        $score += $tags[$tagname];
                    }
                }
                // A tag change occurred, reset counter.
                $tagwords = 0;
            }
            else {
                // Note: use of PREG_SPLIT_DELIM_CAPTURE above will introduce empty values
                if ($value != '') {
                    $words = $this->search_index_split($value);
                    foreach ($words as $word) {
                        if($word != "") {
                            // Add word to accumulator
                            $accum .= $word . ' ';
                            // Check wordlength
                            if (is_numeric($word) || strlen($word) >= $minimum_word_size) {
                                if (!isset($scored_words[$word])) {
                                    $scored_words[$word] = 0;
                                }
                                $scored_words[$word] += $score * $focus;
                                // Focus is a decaying value in terms of the amount of unique words up to this point.
                                // From 100 words and more, it decays, to e.g. 0.5 at 500 words and 0.3 at 1000 words.
                                $focus = min(1, .01 + 3.5 / (2 + count($scored_words) * .015));
                            }
                            $tagwords++;
                            // Too many words inside a single tag probably mean a tag was accidentally left open.
                            if (count($tagstack) && $tagwords >= 15) {
                                $tagstack = array();
                                $score = 1;
                            }
                        }
                    }
                }
            }
            $tag = !$tag;
        }

        foreach ($scored_words as $key => $value) {
            $newIndex = new Index();
            $newIndex->setDataset($dataset);
            $newIndex->setType(strtolower($type));
            $newIndex->setWord($key);
            $newIndex->setLocale($this->container->getParameter('locale'));
            $newIndex->setScore($value);
            $this->em->persist($newIndex);
            $this->em->flush();
            $this->search_dirty($key);
        }
    }

    public function indexingCollection($text, $find, $yamlFile, $mainPath, $dataSet) {
        $colProperties = $yamlFile[$find]['properties'];
        $textContent = null;
        $colTypeYml = null;
        foreach($colProperties as $key => $value) {
            if(array_key_exists($key, $text)) {
                $textContent = $text[$key];
                $colTypeYml = $yamlFile[$value[0]];
            }
        }
        if($textContent != null){
            $splittedFindPath = explode("\\", $find);
            $colItemPath = null;
            $i = 1;
            $colItemPath = $splittedFindPath[0];
            while($splittedFindPath[$i] != 'Entity') {
                $colItemPath = $colItemPath.'\\'.$splittedFindPath[$i];
                $i++;
            }
            foreach($textContent as $current) {
                $currentColItemPath = $colItemPath . '\\Entity\\' . ucfirst($current['type']);
                $currentItem = $yamlFile[$currentColItemPath]['properties'];
                foreach ($colTypeYml['properties'] as $key1 => $value1) {
                    foreach ($currentItem as $key2 => $value2) {
                        $indexingField = $key2;
                        if (array_key_exists($indexingField, $current[$key1])) {
                            $currentText = $current[$key1][$indexingField];
                        }
                        foreach ($value2[0] as $key3 => $value3) {
                            if ($key3 == 'Plain') {
                                $this->indexingPlain($currentText, $value3['weight'], $value3['type'], $dataSet);
                            } else if ($key3 == 'Html') {
                                if (array_key_exists('weights', $value3)) {
                                    $this->indexingHtml($currentText, $value3['type'], $dataSet, $mainPath, $value3['weights']);
                                } else {
                                    $this->indexingHtml($currentText, $value3['type'], $dataSet, $mainPath);
                                }
                            } else if ($key3 == 'Collection') {
                                $collectionPath = $mainPath;
                                $entityPath = $value3['entity'];
                                $splittedEntityPath = explode("\\", $entityPath);
                                $i = 0;
                                while ($splittedEntityPath[$i] != 'Entity') {
                                    $collectionPath = $collectionPath . '/' . $splittedEntityPath[$i];
                                    $i++;
                                }
                                $collectionPath = $collectionPath . '/Resources/config/search.yml';
                                $yaml = new Parser();
                                $currentCollectionSearchYaml = $yaml->parse(file_get_contents($collectionPath));
                                $this->indexingCollection($currentText, $value3['entity'], $currentCollectionSearchYaml, $mainPath, $dataSet);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Marks a word as "dirty" (changed), or retrieves the list of dirty words.
     *
     * This is used during indexing (cron). Words that are dirty have outdated
     * total counts in the search_total table, and need to be recounted.
     */
    function search_dirty($word = NULL) {
        global $dirty;
        if ($word !== NULL) {
            $dirty[$word] = TRUE;
        }
        else {
            return $dirty;
        }
    }

    /**
     * Updates the {search_total} database table.
     *
     * This function is called on shutdown to ensure that {search_total} is always
     * up to date (even if cron times out or otherwise fails).
     */
    function search_update_totals() {
        // Update word IDF (Inverse Document Frequency) counts for new/changed words.
        foreach ($this->search_dirty() as $word => $dummy) {
            // Get total count
            $searchIndexRepository = $this->em->getRepository('EnhavoSearchBundle:Index');
            $total = $searchIndexRepository->sumScoresOfWord($word);
            //$total = db_query("SELECT SUM(score) FROM {search_index} WHERE word = :word", array(':word' => $word), array('target' => 'replica'))->fetchField();
            // Apply Zipf's law to equalize the probability distribution.
            $total = log10(1 + 1/(max(1, current($total))));

            $searchTotalRepository =  $this->em->getRepository('EnhavoSearchBundle:Total');
            $currentWord = $searchTotalRepository->findOneBy(array('word' => $word));

            if($currentWord != null) {
                //Wort schon in search_total vorhanden
                $this->em->remove($currentWord);
                $this->em->flush();
            }
            $newTotal = new Total();
            $newTotal->setWord($word);
            $newTotal->setCount($total);
            $this->em->persist($newTotal);
            $this->em->flush();
        }
        // Find words that were deleted from search_index, but are still in
        // search_total. We use a LEFT JOIN between the two tables and keep only the
        // rows which fail to join.
        $searchTotalRepository = $this->em->getRepository('EnhavoSearchBundle:Total');
        $wordsToRemove = $searchTotalRepository->getWordsToRemove();
        foreach ($wordsToRemove as $word) {
            $currentWordsToRemove = $searchTotalRepository->findBy(array('word' => $word['realword']));
            if($currentWordsToRemove != null){
                foreach($currentWordsToRemove as $currentWordToRemove) {
                    $this->em->remove($currentWordToRemove);
                    $this->em->flush();
                }
            }
        }
    }

    /**
     * Simplifies and preprocesses text for searching.
     *
     * Processing steps:
     * - Entities are decoded.
     * - Text is lower-cased and diacritics (accents) are removed.
     * - hook_search_preprocess() is invoked.
     * - CJK (Chinese, Japanese, Korean) characters are processed, depending on
     *   the search settings.
     * - Punctuation is processed (removed or replaced with spaces, depending on
     *   where it is; see code for details).
     * - Words are truncated to 50 characters maximum.
     *
     * @param string $text
     *   Text to simplify.
     * @param string|null $langcode
     *   Language code for the language of $text, if known.
     *
     * @return string
     *   Simplified and processed text.
     *
     * @see hook_search_preprocess()
     */
    function search_simplify($text) {
        $text = $this->decode_entities($text);
        // Lowercase
        $text = strtolower($text);

        // To improve searching for numerical data such as dates, IP addresses
        // or version numbers, we consider a group of numerical characters
        // separated only by punctuation characters to be one piece.
        // This also means that searching for e.g. '20/03/1984' also returns
        // results with '20-03-1984' in them.
        // Readable regexp: ([number]+)[punctuation]+(?=[number])
        $text = preg_replace('/([' . PREG_CLASS_NUMBERS . ']+)[' . PREG_CLASS_PUNCTUATION . ']+(?=[' . PREG_CLASS_NUMBERS . '])/u', '\1', $text);

        // Multiple dot and dash groups are word boundaries and replaced with space.
        // No need to use the unicode modifier here because 0-127 ASCII characters
        // can't match higher UTF-8 characters as the leftmost bit of those are 1.
        $text = preg_replace('/[.-]{2,}/', ' ', $text);

        // The dot, underscore and dash are simply removed. This allows meaningful
        // search behavior with acronyms and URLs. See unicode note directly above.
        $text = preg_replace('/[._-]+/', '', $text);

        // With the exception of the rules above, we consider all punctuation,
        // marks, spacers, etc, to be a word boundary.
        $text = preg_replace('/[' . self::PREG_CLASS_WORD_BOUNDARY . ']+/u', ' ', $text);

        return $text;
    }

    function decode_entities($text) {
        return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Simplifies and splits a string into words for indexing.
     *
     * @param string $text
     *   Text to process.
     * @param string|null $langcode
     *   Language code for the language of $text, if known.
     *
     * @return array
     *   Array of words in the simplified, preprocessed text.
     *
     * @see search_simplify()
     */
    function search_index_split($text) {
        // Process words
        $text = $this->search_simplify($text);
        $words = explode(' ', $text);

        return $words;
    }

}