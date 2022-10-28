# Search Plus

> **Notice:** This is a quick fix for the pdf search under Contao 4.13 and PHP 7.4 (not 8.1)

This bundle solves the issues found in the original bundle https://github.com/heimrichhannot/contao-search_plus for Contao 4.13.

## Features

- pdf search
- filter pages for search frontend module 
- Support Access-Control-Allow-Origin within be_rebuild_index.html5

### Dependencies

Don't worry, install via composer and all dependencies will be resolved like magic.

- [smalot/pdfparser] (https://github.com/smalot/pdfparser)

### PDF Search

- index pdf files that are referenced inside searched pages and must be locally (contao filesystem) stored
- parse pdf files with smalot/pdfparser
- make usage of meta information from tl_files to provide better file titles (consider language too)
- group the results in the result list
- select the search order (show pages first, show files first or by relenvance)

## Usage

### Install 

Add following line to your composer.json required section:

    "heimrichhannot/contao-search_plus" : "^1.0"
    
And add the forked repo as a repository too:

```
"repositories": [
    {
        "type": "git",
        "url" : "https://github.com/mediamotionag/contao-search-plus"
    }
    
],
```

### Settings

You can disable PDF search on the Contao setting page. If enabled, an option to set the maximum pdf size to parse, is given.