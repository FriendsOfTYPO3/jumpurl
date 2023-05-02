<?php

namespace FoT3\Jumpurl\TypoLink;

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
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Event\AfterLinkIsGeneratedEvent;
use TYPO3\CMS\Frontend\Http\UrlProcessorInterface;
use TYPO3\CMS\Frontend\Typolink\LinkResultInterface;
use TYPO3\CMS\Frontend\Typolink\PageLinkBuilder;

class LinkModifier
{
    /**
     * @var ContentObjectRenderer
     */
    protected $contentObjectRenderer;

    /**
     * @var TypoScriptFrontendController
     */
    protected $frontendController;

    public function __invoke(AfterLinkIsGeneratedEvent $event): void
    {
        if ($this->isEnabled($event)) {
            $url = $event->getLinkResult()->getUrl();
            $context = $event->getLinkResult()->getType();
            $configuration = $event->getLinkResult()->getLinkConfiguration();
            $this->contentObjectRenderer = $event->getContentObjectRenderer();
            $this->frontendController = $this->contentObjectRenderer->getTypoScriptFrontendController();

            // Strip the absRefPrefix from the URLs.
            $urlPrefix = (string)$this->getTypoScriptFrontendController()->absRefPrefix;
            if ($urlPrefix !== '' && str_starts_with($url, $urlPrefix)) {
                $url = substr($url, strlen($urlPrefix));
            }

            // Make sure the slashes in the file URL are not encoded.
            if ($context === LinkService::TYPE_FILE) {
                $url = str_replace('%2F', '/', rawurlencode(rawurldecode($url)));
            }

            if ($context === LinkService::TYPE_PAGE && $url === '') {
                $url = '/';
            }

            $urlParameters = ['jumpurl' => $url];

            $jumpUrlConfig = $configuration['jumpurl.'] ?? [];

            // see if a secure File URL should be built
            if (!empty($jumpUrlConfig['secure'])) {
                $secureParameters = $this->getParametersForSecureFile(
                    $url,
                    $jumpUrlConfig['secure.'] ?? []
                );
                $urlParameters = array_merge($urlParameters, $secureParameters);
            } else {
                $urlParameters['juHash'] = JumpUrlUtility::calculateHash($url);
            }

            $typoLinkConfiguration = [
                'parameter' => $this->getTypoLinkParameter($jumpUrlConfig),
                'additionalParams' => GeneralUtility::implodeArrayForUrl('', $urlParameters),
            ];

            $jumpurl = $this->getContentObjectRenderer()->typoLink_URL($typoLinkConfiguration);

            // Now add the prefix again if it was not added by a typolink call already.
            if ($urlPrefix !== '') {
                if (!str_starts_with($jumpurl, $urlPrefix)) {
                    $jumpurl = $urlPrefix . $jumpurl;
                }
                if (!str_starts_with($url, $urlPrefix)) {
                    $url = $urlPrefix . $url;
                }
            }
            $event->setLinkResult($event->getLinkResult()->withAttributes(['href' => $jumpurl, 'jumpurl' => $url]));
        }
    }

    /**
     * Returns TRUE if jumpurl was enabled in the global configuration
     * or in the given configuration
     *
     * @param AfterLinkIsGeneratedEvent $event
     * @return bool TRUE if enabled, FALSE if disabled
     */
    protected function isEnabled(AfterLinkIsGeneratedEvent $event)
    {
        if (str_contains($event->getLinkResult()->getUrl(), 'juHash=')) {
            return false;
        }

        $configuration = $event->getLinkResult()->getLinkConfiguration();

        $enabled = !empty($configuration['jumpurl'] ?? false);

        // if jumpurl is explicitly set to 0 we override the global configuration
        if (!$enabled && ($this->getTypoScriptFrontendController()->config['config']['jumpurl_enable'] ?? false)) {
            $enabled = !isset($configuration['jumpurl']) || $configuration['jumpurl'];
        }

        // If we have a mailto link and jumpurl is not explicitly enabled
        // but globally disabled for mailto links we disable it
        if (
            empty($configuration['jumpurl']) && $event->getLinkResult()->getType() === LinkService::TYPE_EMAIL
            && ($this->getTypoScriptFrontendController()->config['config']['jumpurl_mailto_disable'] ?? false)
        ) {
            $enabled = false;
        }

        return $enabled;
    }

    /**
     * Returns a URL parameter array containing parameters for secure downloads by "jumpurl".
     * Helper function for filelink()
     *
     * The array returned has the following structure:
     * juSecure => is always 1,
     * locationData => information about the record that created the jumpUrl,
     * juHash => the hash that will be checked before the file is downloadable
     * [mimeType => the mime type of the file]
     *
     * @param string $jumpUrl The URL to jump to, basically the filepath
     * @param array $configuration TypoScript properties for the "jumpurl.secure" property of "filelink"
     * @return array URL parameters required for jumpUrl secure
     */
    protected function getParametersForSecureFile($jumpUrl, array $configuration)
    {
        $parameters = [
            'juSecure' => 1,
            'locationData' => $this->getTypoScriptFrontendController()->id . ':' . $this->getContentObjectRenderer()->currentRecord
        ];

        $pathInfo = pathinfo($jumpUrl);
        if (!empty($pathInfo['extension'])) {
            $mimeTypes = GeneralUtility::trimExplode(',', $configuration['mimeTypes'], true);
            foreach ($mimeTypes as $mimeType) {
                [$fileExtension, $mimeType] = GeneralUtility::trimExplode('=', $mimeType, false, 2);
                if (strtolower($pathInfo['extension']) === strtolower($fileExtension)) {
                    $parameters['mimeType'] = $mimeType;
                    break;
                }
            }
        }
        $parameters['juHash'] = JumpUrlUtility::calculateHashSecure($jumpUrl, $parameters['locationData'],
            $parameters['mimeType']);
        return $parameters;
    }

    /**
     * Checks if an alternative link parameter was configured and if not
     * a default parameter will be generated based on the current page
     * ID and type.
     * When linking to a file this method is needed
     *
     * @param array $configuration Data from the TypoLink jumpurl configuration
     * @return string The parameter for the jump URL TypoLink
     */
    protected function getTypoLinkParameter(array $configuration)
    {
        $linkParameter = $this->getContentObjectRenderer()->stdWrapValue('parameter', $configuration);

        if (empty($linkParameter)) {
            $frontendController = $this->getTypoScriptFrontendController();
            $linkParameter = $frontendController->id . ',' . $frontendController->type;
        }

        return $linkParameter;
    }

    protected function getTypoScriptFrontendController(): TypoScriptFrontendController
    {
        $tsfe = $this->frontendController ?? $GLOBALS['TSFE'] ?? null;
        if ($tsfe instanceof TypoScriptFrontendController) {
            return $tsfe;
        }
        // workaround for getting a TSFE object in Backend
        $linkBuilder = GeneralUtility::makeInstance(PageLinkBuilder::class, new ContentObjectRenderer());
        return $linkBuilder->getTypoScriptFrontendController();
    }

    protected function getContentObjectRenderer(): ContentObjectRenderer
    {
        return $this->contentObjectRenderer ?: $this->getTypoScriptFrontendController()->cObj;
    }
}
