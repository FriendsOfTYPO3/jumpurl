<?php
defined('TYPO3') or defined('TYPO3_MODE') or die();

// Register hook
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['urlProcessing']['urlProcessors']['jumpurl']['processor'] = \FoT3\Jumpurl\JumpUrlProcessor::class;
