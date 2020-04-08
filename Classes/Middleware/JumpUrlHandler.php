<?php
namespace FoT3\Jumpurl\Middleware;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use FoT3\Jumpurl\JumpUrlUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * This class implements the hooks for the JumpURL functionality when accessing a page
 * which has a GET parameter "jumpurl".
 *
 * If a valid hash was submitted the user will either be redirected
 * to the given jumpUrl or if it is a secure jumpUrl the file data
 * will be passed to the user.
 *
 * This middleware needs to take care of redirecting the user or generating custom output.
 * This middleware will be executed BEFORE the user is redirected to an external URL configured in the page properties.
 */
class JumpUrlHandler implements MiddlewareInterface
{
    /**
     * @var TimeTracker
     */
    protected $timeTracker;

    /**
     * @var TypoScriptFrontendController
     */
    protected $typoScriptFrontendController;

    public function __construct(TypoScriptFrontendController $typoScriptFrontendController = null, TimeTracker $timeTracker = null)
    {
        $this->typoScriptFrontendController = $typoScriptFrontendController ?? $GLOBALS['TSFE'];
        $this->timeTracker = $timeTracker ?? GeneralUtility::makeInstance(TimeTracker::class);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $jumpUrl = (string)$request->getQueryParams()['jumpurl'] ?? '';
        if (!empty($jumpUrl)) {
            $juHash = (string)$request->getQueryParams()['juHash'] ?? '';
            if (!empty($request->getQueryParams()['juSecure'])) {
                $locationData = (string)$request->getQueryParams()['locationData'] ?? '';
                $mimeType = (string)$request->getQueryParams()['mimeType'] ?? '';
                return $this->forwardJumpUrlSecureFileData($jumpUrl, $locationData, $mimeType, $juHash);
            }
            // Regular jump URL
            $this->validateIfJumpUrlRedirectIsAllowed($jumpUrl, $juHash);
            return $this->redirectToJumpUrl($jumpUrl);
        }
        return $handler->handle($request);
    }
    /**
     * Redirects the user to the given jump URL if all submitted values
     * are valid (checked before)
     *
     * @param string $jumpUrl The URL to which the user should be redirected
     * @throws \Exception
     * @return ResponseInterface
     */
    protected function redirectToJumpUrl(string $jumpUrl): ResponseInterface
    {
        $pageTSconfig = $this->getTypoScriptFrontendController()->getPagesTSconfig();
        $pageTSconfig = is_array($pageTSconfig['TSFE.']) ? $pageTSconfig['TSFE.'] : [];

        // Allow sections in links
        $jumpUrl = str_replace('%23', '#', $jumpUrl);
        $jumpUrl = $this->addParametersToTransferSession($jumpUrl, $pageTSconfig);

        $statusCode = $this->getRedirectStatusCode($pageTSconfig);
        return new RedirectResponse($jumpUrl, $statusCode);
    }

    /**
     * If the submitted hash is correct and the user has access to the
     * related content element the contents of the submitted file will
     * be output to the user.
     *
     * @param string $jumpUrl The URL to the file that should be output to the user
     * @param string $locationData locationData GET parameter, containing information about the record that created the URL
     * @param string $mimeType The optional mimeType GET parameter
     * @param string $juHash jump Url Hash GET parameter
     * @return ResponseInterface
     * @throws \Exception
     */
    protected function forwardJumpUrlSecureFileData(string $jumpUrl, string $locationData, string $mimeType, string $juHash): ResponseInterface
    {
        // validate the hash GET parameter against the other parameters
        if ($juHash !== JumpUrlUtility::calculateHashSecure($jumpUrl, $locationData, $mimeType)) {
            throw new \Exception('The calculated Jump URL secure hash ("juHash") did not match the submitted "juHash" query parameter.', 1294585196);
        }

        if (!$this->isLocationDataValid($locationData)) {
            throw new \Exception('The calculated secure location data "' . $locationData . '" is not accessible.', 1294585195);
        }

        // Allow spaces / special chars in filenames.
        $jumpUrl = rawurldecode($jumpUrl);

        // Deny access to files that match TYPO3_CONF_VARS[SYS][fileDenyPattern] and whose parent directory
        // is typo3conf/ (there could be a backup file in typo3conf/ which does not match against the fileDenyPattern)
        $absoluteFileName = GeneralUtility::getFileAbsFileName(GeneralUtility::resolveBackPath($jumpUrl));
        if (
            !GeneralUtility::isAllowedAbsPath($absoluteFileName)
            || !GeneralUtility::verifyFilenameAgainstDenyPattern($absoluteFileName)
            || GeneralUtility::isFirstPartOfStr($absoluteFileName, Environment::getLegacyConfigPath())
        ) {
            throw new \Exception('The requested file was not allowed to be accessed through Jump URL. The path or file is not allowed.', 1294585194);
        }

        try {
            $resourceFactory = $this->getResourceFactory();
            $file = $resourceFactory->retrieveFileOrFolderObject($absoluteFileName);
        } catch (\Exception $e) {
            throw new \Exception('The requested file "' . $jumpUrl . '" for Jump URL was not found..', 1294585193);
        }
        return $file->getStorage()->streamFile($file, true, null, $mimeType);
    }

    /**
     * Checks if the given location data is valid and the connected record is accessible by the current user.
     *
     * @param string $locationData
     * @return bool
     */
    protected function isLocationDataValid(string $locationData): bool
    {
        $isValidLocationData = false;
        list($pageUid, $table, $recordUid) = explode(':', $locationData);
        $pageRepository = $this->getTypoScriptFrontendController()->sys_page;
        if (empty($table) || $pageRepository->checkRecord($table, $recordUid, true)) {
            // This check means that a record is checked only if the locationData has a value for a
            // record else than the page.
            if (!empty($pageRepository->getPage($pageUid))) {
                $isValidLocationData = true;
            } else {
                $this->timeTracker->setTSlogMessage('LocationData Error: The page pointed to by location data "' . $locationData . '" was not accessible.', 2);
            }
        } else {
            $this->timeTracker->setTSlogMessage('LocationData Error: Location data "' . $locationData . '" record pointed to was not accessible.', 2);
        }
        return $isValidLocationData;
    }

    /**
     * This implements a hook, e.g. for direct mail to allow the redirects but only if the handler says it's alright
     * But also checks against the common juHash parameter first
     *
     * @param string $jumpUrl the URL to check
     * @param string $submittedHash the "juHash" GET parameter
     * @throws \Exception thrown if no redirect is allowed
     */
    protected function validateIfJumpUrlRedirectIsAllowed(string $jumpUrl, string $submittedHash): void
    {
        if ($this->isJumpUrlHashValid($jumpUrl, $submittedHash)) {
            return;
        }

        $allowRedirect = false;
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['jumpurlRedirectHandler'] ?? [] as $className) {
            $hookObject = GeneralUtility::makeInstance($className);
            if (method_exists($hookObject, 'jumpurlRedirectHandler')) {
                $allowRedirect = $hookObject->jumpurlRedirectHandler($jumpUrl, $this->getTypoScriptFrontendController());
            }
            if ($allowRedirect) {
                return;
            }
        }

        throw new \Exception('The calculated Jump URL hash ("juHash") did not match the submitted "juHash" query parameter.', 1359987599);
    }

    /**
     * Validate the jumpUrl hash against the GET/POST parameter "juHash".
     *
     * @param string $jumpUrl The URL to check against.
     * @param string $submittedHash the "juHash" GET parameter
     * @return bool
     */
    protected function isJumpUrlHashValid(string $jumpUrl, string $submittedHash): bool
    {
        return $submittedHash === JumpUrlUtility::calculateHash($jumpUrl);
    }

    /**
     * Modified the URL to go to by adding the session key information to it
     * but only if TSFE.jumpUrl_transferSession = 1 is set via pageTSconfig.
     *
     * @param string $jumpUrl the URL to go to
     * @param array $pageTSconfig the TSFE. part of the TS configuration
     *
     * @return string the modified URL
     */
    protected function addParametersToTransferSession(string $jumpUrl, array $pageTSconfig): string
    {
        // allow to send the current fe_user with the jump URL
        if (!empty($pageTSconfig['jumpUrl_transferSession'])) {
            $uParts = parse_url($jumpUrl);
            /** @noinspection PhpInternalEntityUsedInspection We need access to the current frontend user ID. */
            $params = '&FE_SESSION_KEY=' .
                rawurlencode(
                    $this->getTypoScriptFrontendController()->fe_user->id . '-' .
                    md5(
                        $this->getTypoScriptFrontendController()->fe_user->id . '/' .
                        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']
                    )
                );
            // Add the session parameter ...
            $jumpUrl .= ($uParts['query'] ? '' : '?') . $params;
        }
        return $jumpUrl;
    }

    /**
     * Returns a valid Redirect HTTP Response status code that matches
     * the configured HTTP status code in TSFE.jumpURL_HTTPStatusCode Page TSconfig.
     *
     * @param array $pageTSconfig
     * @return int
     * @throws \InvalidArgumentException If the configured status code is not valid.
     */
    protected function getRedirectStatusCode(array $pageTSconfig): int
    {
        $statusCode = 303;
        if (!empty($pageTSconfig['jumpURL_HTTPStatusCode'])) {
            switch ((int)$pageTSconfig['jumpURL_HTTPStatusCode']) {
                case 301:
                case 302:
                case 307:
                    $statusCode = (int)$pageTSconfig['jumpURL_HTTPStatusCode'];
                    break;
                default:
                    throw new \InvalidArgumentException('The configured jumpURL_HTTPStatusCode option is invalid. Allowed codes are 301, 302 and 307.', 1381768833);
            }
        }

        return $statusCode;
    }

    protected function getResourceFactory(): ResourceFactory
    {
        return GeneralUtility::makeInstance(ResourceFactory::class);
    }

    protected function getTypoScriptFrontendController(): TypoScriptFrontendController
    {
        return $this->typoScriptFrontendController ?? $GLOBALS['TSFE'];
    }
}
