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

    public function __construct(TypoScriptFrontendController $typoScriptFrontendController = null, ContentObjectRenderer $contentObjectRenderer = null)
    {
        $this->frontendController = $typoScriptFrontendController ?? $GLOBALS['TSFE'];
        $this->contentObjectRenderer = $contentObjectRenderer;
    }

    public function __invoke(AfterLinkIsGeneratedEvent $event): void
    {
        $linkInstructions = $event->getLinkInstructions();

        // todo get context and configuration
        $context = 'common';
        $context = $event->getLinkResult()->getType();
        $configuration = $event->getLinkResult()->getLinkConfiguration();
        
        if ($this->isEnabled($context, $configuration)) {
            $this->contentObjectRenderer = $event->getContentObjectRenderer();

            $url = $event->getLinkResult()->getUrl();
            //$url = $event->getLinkResult()->withAttribute('data-enable-lightbox', true);
            // todo modify link
            // Strip the absRefPrefix from the URLs.
            $urlPrefix = (string)$this->getTypoScriptFrontendController()->absRefPrefix;
            if ($urlPrefix !== '' && StringUtility::beginsWith($url, $urlPrefix)) {
                $url = substr($url, strlen($urlPrefix));
            }
            
            // Make sure the slashes in the file URL are not encoded.
            // LinkService::TYPE_FILE
            if ($context === UrlProcessorInterface::CONTEXT_FILE) {
                $url = str_replace('%2F', '/', rawurlencode(rawurldecode($url)));
            }

            $url = $this->build($url, $configuration['jumpurl.'] ?? []);

            // Now add the prefix again if it was not added by a typolink call already.
            if ($urlPrefix !== '' && !StringUtility::beginsWith($url, $urlPrefix)) {
                $url = $urlPrefix . $url;
            }
            $event->setLinkResult($url);
        }
    }
    
    /**
     * Returns TRUE if jumpurl was enabled in the global configuration
     * or in the given configuration
     *
     * @param string $context separate check for the MAIL context needed
     * @param array $configuration Optional jump URL configuration
     * @return bool TRUE if enabled, FALSE if disabled
     */
    protected function isEnabled($context, array $configuration = [])
    {
        if (!empty($configuration['jumpurl.']['forceDisable'] ?? false)) {
            return false;
        }

        $enabled = !empty($configuration['jumpurl'] ?? false);

        // if jumpurl is explicitly set to 0 we override the global configuration
        if (!$enabled && ($this->getTypoScriptFrontendController()->config['config']['jumpurl_enable'] ?? false)) {
            $enabled = !isset($configuration['jumpurl']) || $configuration['jumpurl'];
        }

        // If we have a mailto link and jumpurl is not explicitly enabled
        // but globally disabled for mailto links we disable it
        // LinkService::TYPE_EMAIL
        if (
            empty($configuration['jumpurl']) && $context === UrlProcessorInterface::CONTEXT_MAIL
            && ($this->getTypoScriptFrontendController()->config['config']['jumpurl_mailto_disable'] ?? false)
        ) {
            $enabled = false;
        }

        return $enabled;
    }

    /**
     * Builds a jump URL for the given URL
     *
     * @param string $url The URL to which will be jumped
     * @param array $configuration Optional TypoLink configuration
     * @return string The generated URL
     */
    protected function build($url, array $configuration)
    {
        $urlParameters = ['jumpurl' => $url];

        // see if a secure File URL should be built
        if (!empty($configuration['secure'])) {
            $secureParameters = $this->getParametersForSecureFile(
                $url,
                isset($configuration['secure.']) ? $configuration['secure.'] : []
            );
            $urlParameters = array_merge($urlParameters, $secureParameters);
        } else {
            $urlParameters['juHash'] = JumpUrlUtility::calculateHash($url);
        }

        $typoLinkConfiguration = [
            'parameter' => $this->getTypoLinkParameter($configuration),
            'additionalParams' => GeneralUtility::implodeArrayForUrl('', $urlParameters),
            // make sure jump URL is not called again
            'jumpurl.' => ['forceDisable' => '1']
        ];

        return $this->getContentObjectRenderer()->typoLink_URL($typoLinkConfiguration);
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
        $parameters['juHash'] = JumpUrlUtility::calculateHashSecure($jumpUrl, $parameters['locationData'], $parameters['mimeType']);
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
