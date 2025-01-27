<?php
/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2015 Heimrich & Hannot GmbH
 *
 * @package search_plus
 * @author  Rico Kaltofen <r.kaltofen@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */

namespace HeimrichHannot\SearchPlus;


use Contao\Database;
use Contao\System;

class Search
{
    /**
     * Remove a page from the search index
     *
     * @param $strUrl
     *
     * @return bool
     */
    public static function removePageFromIndex($strUrl)
    {
        $objIndex = \Database::getInstance()->prepare("SELECT id, checksum FROM tl_search WHERE url LIKE '" . $strUrl . "%%'")
            ->execute($strUrl);

        if ($objIndex->numRows < 1) {
            return false;
        }

        $arrIds = $objIndex->fetchEach('id');

        \Database::getInstance()->execute("DELETE FROM tl_search WHERE id IN(" . implode(',', array_map('intval', $arrIds)) . ")");
        \Database::getInstance()->execute("DELETE FROM tl_search_index WHERE pid IN(" . implode(',', array_map('intval', $arrIds)) . ")");

        return true;
    }

    public static function truncate($arrSet)
    {
        // Return if the page is indexed and up to date
        $objIndex = \Database::getInstance()->prepare("SELECT id FROM tl_search WHERE url=? AND pid=?")
            ->limit(1)
            ->execute($arrSet['url'], $arrSet['pid']);

        if ($objIndex->numRows < 1) {
            return false;
        }

        // Remove keywords
        \Database::getInstance()->prepare("DELETE FROM tl_search_index WHERE pid=?")
            ->execute($objIndex->id);

        // Remove result
        \Database::getInstance()->prepare("DELETE FROM tl_search WHERE id=?")
            ->execute($objIndex->id);
    }

    public static function indexFiles(array $arrLinks, $arrParentSet)
    {
        foreach ($arrLinks as $strFile) {
            if (($strFile = static::getValidPath($strFile, [\Environment::get('host')])) === null) {
                continue;
            }

            static::addToPDFSearchIndex($strFile, $arrParentSet);
        }
            }

    protected static function addToPDFSearchIndex($strFile, $arrParentSet)
    {
        $objFile = new \File($strFile);

        if (!Validator::isValidPDF($objFile)) {
            return false;
        }
    
        $objDatabase = \Database::getInstance();

        $objModel = $objFile->getModel();

        $arrMeta = \Frontend::getMetaData($objModel->meta, $arrParentSet['language']);
        
        // Use the file name as title if none is given
        if ($arrMeta['title'] == '') {
            $arrMeta['title'] = specialchars($objFile->basename);
        }

        $strHref = \Environment::get('base') . \Environment::get('request');

        // Remove an existing file parameter
        if (preg_match('/(&(amp;)?|\?)file=/', $strHref)) {
            $strHref = preg_replace('/(&(amp;)?|\?)file=[^&]+/', '', $strHref);
        }

        $strHref .= ((\Config::get('disableAlias') || strpos($strHref, '?') !== false) ? '&amp;' : '?') . 'file=' . \System::urlEncode($objFile->value);

        $filesize = round($objFile->size / 1024, 2);

        $arrSet = [
            'pid'       => $arrParentSet['pid'],
            'tstamp'    => time(),
            'title'     => $arrMeta['title'],
            'url'       => $strHref,
            'filesize'  => $filesize,
            'checksum'  => $objFile->hash,
            'protected' => $arrParentSet['protected'],
            'groups'    => $arrParentSet['groups'],
            'language'  => $arrParentSet['language'],
            'mime'      => $objFile->mime
        ];
                
        // Return if the file is indexed and up to date
        $objIndex = $objDatabase->prepare("SELECT * FROM tl_search WHERE pid=? AND checksum=?")
            ->execute($arrSet['pid'], $arrSet['checksum']);
            

        // there are already indexed files containing this file (same checksum and filename)
        if ($objIndex->numRows) {
            // Return if the page with the file is indexed
            if (in_array($arrSet['pid'], $objIndex->fetchEach('pid'))) {
                return false;
            }

            $strContent = $objIndex->text;
        } else {

            // return if file size higher than max file size settings
            if (\Config::get('search_pdfMaxParsingSize') > 0 && $arrSet['filesize'] > \Config::get('search_pdfMaxParsingSize')) {
                return false;
            }

            try {
                // parse only for the first occurrence
                $parser     = new \Smalot\PdfParser\Parser();
                $objPDF     = $parser->parseFile($strFile);
                
                $strContent = $objPDF->getText();

            } catch (\Exception $e) {
                // Missing object refernce #...
                return false;
            }
        }

        // Put everything together
        $strContent = trim(preg_replace('/ +/', ' ', \StringUtil::decodeEntities($strContent)));

        // save only first 2000 characters for performance reasons
        $arrSet['text'] = substr($strContent, 0, 2000);
        
        $arrSet['text'] = mb_substr($arrSet['text'],0,156,"UTF-8");
                
        // Update an existing old entry
        if ($objIndex->count() > 0 && $objIndex->pid == $arrSet['pid']) {
            $objDatabase->prepare("UPDATE tl_search %s WHERE id=?")
                ->set($arrSet)
                ->execute($objIndex->id);

            $intInsertId = $objIndex->id;
        } else {
            $objInsertStmt = $objDatabase->prepare("INSERT INTO tl_search %s")->set($arrSet)->execute();
            

            $intInsertId = $objInsertStmt->insertId;
        }

        static::indexContent($arrSet, $strContent, $intInsertId);
    }


    protected static function indexContent($arrSet, $strContent, $pid)
    {
        $objDatabase = Database::getInstance();

        // Remove quotes
        $strText = $arrSet['title'] . ' ' . $strContent;
        $strText = str_replace(['´', '`'], "'", $strText);

        unset($strContent);

        // Remove special characters
        if (function_exists('mb_eregi_replace')) {
            $strText = mb_eregi_replace('[^[:alnum:]\'\.:,\+_-]|- | -|\' | \'|\. |\.$|: |:$|, |,$', ' ', $strText);
        } else {
            $strText = preg_replace(['/- /', '/ -/', "/' /", "/ '/", '/\. /', '/\.$/', '/: /', '/:$/', '/, /', '/,$/', '/[^\w\'.:,+-]/u'], ' ', $strText);
        }

        // Split words
        $arrWords = preg_split('/ +/', utf8_strtolower($strText));
        $arrIndex = [];

        // Index words
        foreach ($arrWords as $strWord) {
            // Strip a leading plus (see #4497)
            if (strncmp($strWord, '+', 1) === 0) {
                $strWord = substr($strWord, 1);
            }

            $strWord   = trim($strWord);
            $strLength = strlen($strWord);

            if (!$strLength || preg_match('/^[\.:,\'_-]+$/', $strWord) || $strLength < 3) {
                continue;
            }

            if (strpos($strWord, '.pdf')) {
                continue;
            }

            // do not index numbers
            if (preg_match('/\d+/', $strWord)) {
                continue;
            }

            if (preg_match('/^[\':,]/', $strWord)) {
                $strWord = substr($strWord, 1);
            }

            if (preg_match('/[\':,\.]$/', $strWord)) {
                $strWord = substr($strWord, 0, -1);
            }

            if (isset($arrIndex[$strWord])) {
                $arrIndex[$strWord]++;
                continue;
            }

            $arrIndex[$strWord] = 1;
        }

        // Remove existing index
        $objDatabase->prepare("DELETE FROM tl_search_index WHERE pid=?")
            ->execute($pid);

        // Create new index
        $arrIndexes = array();

        foreach ($arrIndex as $k => $v) {
            
            $objTerm = $objDatabase->prepare("SELECT * FROM tl_search_term WHERE term=?")->execute($k);
            $arrResult = $objTerm->fetchAllAssoc();
            
            if(is_array($arrResult) && count($arrResult) > 0){
                $intID = $arrResult[0]['id'];
                $arrIndexes[$intID] = $v;
            } else {
                try
                {
                    $objInsertStmt = $objDatabase->prepare("INSERT INTO tl_search_term (term, documentFrequency) VALUES (?, ?)")
                        ->execute($k, $v);
                    $arrIndexes[$objInsertStmt->insertId] = $v;
                } catch (\Exception $exception) {
                    System::log("Error while updating search term table: ". $exception->getMessage(), __METHOD__, TL_ERROR);
                }
            }
            
        }
        
        if(is_array($arrIndexes)){
            foreach ($arrIndexes as $k => $v) {
                
                try
                {
                    $objDatabase->prepare("INSERT INTO tl_search_index (pid, termId, relevance) VALUES (?, ?, ?)")
                        ->execute($pid, $k, $v);
                } catch (\Exception $exception) {
                    System::log("Error while adding search index item: ". $exception->getMessage(), __METHOD__, TL_ERROR);
                }
            }
        }
    }


    public static function getValidPath($varValue, array $arrHosts = [])
    {
        $arrUrl = parse_url($varValue);

        $strFile = $arrUrl['path'];

        // linked pdf is an valid absolute url
        if (isset($arrUrl['scheme']) && in_array($arrUrl['scheme'], ['http', 'https'])) {
            if (isset($arrUrl['host']) && !in_array($arrUrl['host'], $arrHosts)) {
                $strFile = null;
            }
        }

        // check for download link
        if (isset($arrUrl['query']) && preg_match('#file=(?<path>.*.pdf)#i', $arrUrl['query'], $m)) {
            $strFile = $m['path'];
        }


        // check if file exists
        if ($strFile !== null) {
            $strFile = ltrim(urldecode($strFile), '/');

            if (!file_exists(TL_ROOT . '/' . $strFile)) {
                $strFile = null;
            }
        }

        return $strFile;
    }


}
