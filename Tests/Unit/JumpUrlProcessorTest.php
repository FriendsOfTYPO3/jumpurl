<?php
namespace FoT3\Jumpurl\Tests\Unit;

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

use Nimut\TestingFramework\TestCase\UnitTestCase;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Testcase for handling jump URLs when given with a test parameter
 */
class JumpUrlProcessorTest extends UnitTestCase
{
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|ContentObjectRenderer
     */
    protected $contentObjectRenderer;

    /**
     * The default location data used for JumpUrl secure.
     *
     * @var string
     */
    protected $defaultLocationData = '1234:tt_content:999';

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|JumpUrlProcessorMock
     */
    protected $jumpUrlProcessor;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\TYPO3\CMS\Core\Tests\AccessibleObjectInterface|\TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController
     */
    protected $tsfe;

    /**
     * Sets environment variables and initializes global mock object.
     */
    protected function setUp()
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '12345';

        $this->tsfe = $this->getAccessibleMock(
            TypoScriptFrontendController::class,
            ['getPagesTSconfig'],
            [],
            '',
            false
        );

        $this->contentObjectRenderer = new ContentObjectRenderer($this->tsfe);
        $this->jumpUrlProcessor = new JumpUrlProcessorMock($this->tsfe, $this->contentObjectRenderer);
    }

    /**
     * @test
     */
    public function getJumpUrlSecureParametersReturnsValidParameters()
    {
        $this->tsfe->id = 456;
        $this->contentObjectRenderer->currentRecord = 'tt_content:123';

        $jumpUrlSecureParameters = $this->jumpUrlProcessor->getParametersForSecureFile(
            '/fileadmin/a/test/file.txt',
            ['mimeTypes' => 'dummy=application/x-executable,txt=text/plain']
        );

        $this->assertSame(
            [
                'juSecure' => 1,
                'locationData' => '456:tt_content:123',
                'mimeType' => 'text/plain',
                'juHash' => '1cccb7f01c8a3f58ee890377b5de9bdc05115a37',
            ],
            $jumpUrlSecureParameters
        );
    }
}
