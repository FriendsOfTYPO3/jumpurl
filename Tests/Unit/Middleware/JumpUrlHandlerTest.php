<?php
namespace FoT3\Jumpurl\Tests\Unit\Middleware;

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
use FoT3\Jumpurl\Middleware\JumpUrlHandler;
use Nimut\TestingFramework\TestCase\UnitTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NullResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Testcase for handling jump URLs when given with a test parameter
 */
class JumpUrlHandlerTest extends UnitTestCase
{
    /**
     * The default location data used for JumpUrl secure.
     *
     * @var string
     */
    protected $defaultLocationData = '1234:tt_content:999';

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|JumpUrlHandler
     */
    protected $jumpUrlHandler;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\TYPO3\CMS\Core\Tests\AccessibleObjectInterface|\TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController
     */
    protected $tsfe;

    /**
     * @var RequestHandlerInterface
     */
    protected $defaultRequestHandler;
    /**
     * Sets environment variables and initializes global mock object.
     */
    protected function setUp()
    {
        parent::setUp();

        $this->defaultRequestHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new NullResponse();
            }
        };
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '12345';
        $this->tsfe = $this->getAccessibleMock(
            TypoScriptFrontendController::class,
            ['getPagesTSconfig'],
            [],
            '',
            false
        );
        $this->jumpUrlHandler = $this->getMockBuilder(JumpUrlHandler::class)
            ->setMethods(
                ['isLocationDataValid', 'getResourceFactory', 'getTypoScriptFrontendController', 'readFileAndExit', 'redirect']
            )
            ->setConstructorArgs([$this->tsfe, new TimeTracker(false)])
            ->getMock();
    }

    /**
     * Provides a valid jump URL hash and a target URL
     *
     * @return array
     */
    public function jumpUrlDefaultValidParametersDataProvider()
    {
        return [
            'File with spaces and ampersands' => [
                '691dbf63a21181e2d69bf78e61f1c9fd023aef2c',
                str_replace('%2F', '/', rawurlencode('typo3temp/phpunitJumpUrlTestFile with spaces & amps.txt')),
            ],
            'External URL' => [
                '7d2261b12682a4b73402ae67415e09f294b29a55',
                'http://www.mytesturl.tld',
            ],
            'External URL with GET parameters' => [
                'cfc95f583da7689238e98bbc8930ebd820f0d20f',
                'http://external.domain.tld?parameter1=' . rawurlencode('parameter[data]with&a lot-of-special/chars'),
            ],
            'External URL without www' => [
                '8591c573601d17f37e06aff4ac14c78f107dd49e',
                'http://external.domain.tld',
            ],
            'URL with section' => [
                '3723eefe766e38f9a2ea8d2cd84f6519d7d30b75',
                str_replace('%23', '#', 'http://external.domain.tld/' . rawurlencode('#c123')),
            ],
            'Mailto link' => [
                'bd82328dc40755f5d0411e2e16e7c0cbf33b51b7',
                'mailto:mail@ddress.tld',
            ]
        ];
    }

    /**
     * @test
     * @dataProvider jumpUrlDefaultValidParametersDataProvider
     * @param string $hash
     * @param string $jumpUrl
     */
    public function jumpUrlDefaultAcceptsValidUrls($hash, $jumpUrl)
    {
        $request = new ServerRequest('https://example.com/');
        $request = $request->withQueryParams(['juHash' => $hash, 'jumpurl' => $jumpUrl]);
        $subject = new JumpUrlHandler($this->tsfe, new TimeTracker(false));
        $response = $subject->process($request, $this->defaultRequestHandler);
        self::assertEquals(303, $response->getStatusCode());
    }

    /**
     * @test
     * @dataProvider jumpUrlDefaultValidParametersDataProvider
     * @param string $hash
     * @param string $jumpUrl
     */
    public function jumpUrlDefaultFailsOnInvalidHash($hash, $jumpUrl)
    {
        self::expectException(\Exception::class);
        self::expectExceptionCode(1359987599);
        $request = new ServerRequest('https://example.com/');
        $request = $request->withQueryParams(['juHash' => $hash . '1', 'jumpurl' => $jumpUrl]);
        $subject = new JumpUrlHandler($this->tsfe, new TimeTracker(false));
        $subject->process($request, $this->defaultRequestHandler);
    }

    /**
     * @test
     * @dataProvider jumpUrlDefaultValidParametersDataProvider
     * @param string $hash
     * @param string $jumpUrl
     */
    public function jumpUrlDefaultTransfersSession($hash, $jumpUrl)
    {
        $tsConfig['TSFE.']['jumpUrl_transferSession'] = 1;

        $frontendUser = new \stdClass();
        $frontendUser->id = 123;

        $this->tsfe->fe_user = $frontendUser;
        $this->tsfe->expects($this->once())
            ->method('getPagesTSconfig')
            ->will($this->returnValue($tsConfig));

        $sessionGetParameter = (strpos($jumpUrl, '?') === false ? '?' : '') . '&FE_SESSION_KEY=123-fc9f825a9af59169895f3bb28267a42f';
        $expectedJumpUrl = $jumpUrl . $sessionGetParameter;

        $request = new ServerRequest('https://example.com/');
        $request = $request->withQueryParams(['juHash' => $hash, 'jumpurl' => $jumpUrl]);

        $subject = new JumpUrlHandler($this->tsfe, new TimeTracker(false));
        $response = $subject->process($request, $this->defaultRequestHandler);
        self::assertEquals(303, $response->getStatusCode());
        self::assertEquals($expectedJumpUrl, $response->getHeaderLine('Location'));
    }

    /**
     * Provides a valid jump secure URL hash, a file path and related
     * record data
     *
     * @return array
     */
    public function jumpUrlSecureValidParametersDataProvider()
    {
        return [
            [
                '1933f3c181db8940acfcd4d16c74643947179948',
                'typo3temp/phpunitJumpUrlTestFile.txt',
            ],
            [
                '304b8c8e022e92e6f4d34e97395da77705830818',
                str_replace('%2F', '/', rawurlencode('typo3temp/phpunitJumpUrlTestFile with spaces & amps.txt')),
            ],
            [
                '304b8c8e022e92e6f4d34e97395da77705830818',
                str_replace('%2F', '/', rawurlencode('typo3temp/phpunitJumpUrlTestFile with spaces & amps.txt')),
            ]
        ];
    }

    /**
     * @test
     * @dataProvider jumpUrlSecureValidParametersDataProvider
     * @param string $hash
     * @param string $jumpUrl
     */
    public function jumpUrlSecureAcceptsValidUrls($hash, $jumpUrl)
    {
        $fileMock = $this->getMockBuilder(File::class)->disableOriginalConstructor()->setMethods(['dummy'])->getMock();
        $storageMock = $this->getMockBuilder(ResourceStorage::class)->disableOriginalConstructor()->setMethods(['streamFile'])->getMock();
        $resourceFactoryMock = $this->getMockBuilder(ResourceFactory::class)->disableOriginalConstructor()->setMethods(['retrieveFileOrFolderObject'])->getMock();
        $fileMock->setStorage($storageMock);

        $resourceFactoryMock->expects($this->once())
            ->method('retrieveFileOrFolderObject')
            ->will($this->returnValue($fileMock));

        $storageMock->expects($this->once())
            ->method('streamFile')
            ->will($this->returnValue(new Response(null, 200, ['X-TYPO3-Test' => 'JumpURL Download Test'])));

        $this->jumpUrlHandler->expects($this->once())
            ->method('isLocationDataValid')
            ->with($this->defaultLocationData)
            ->will($this->returnValue(true));

        $this->jumpUrlHandler->expects($this->once())
            ->method('getResourceFactory')
            ->will($this->returnValue($resourceFactoryMock));

        $request = $this->prepareJumpUrlSecureTest($jumpUrl, $hash);
        $response = $this->jumpUrlHandler->process($request, $this->defaultRequestHandler);
        self::assertEquals('JumpURL Download Test', $response->getHeaderLine('X-TYPO3-Test'));
    }

    /**
     * @test
     * @dataProvider jumpUrlSecureValidParametersDataProvider
     * @param string $hash
     * @param string $jumpUrl
     */
    public function jumpUrlSecureFailsIfFileDoesNotExist($hash, $jumpUrl)
    {
        $resourceFactoryMock = $this->getMockBuilder(ResourceFactory::class)->disableOriginalConstructor()->setMethods(['retrieveFileOrFolderObject'])->getMock();
        $resourceFactoryMock->expects($this->once())
            ->method('retrieveFileOrFolderObject')
            ->will($this->throwException(new FileDoesNotExistException()));

        $this->jumpUrlHandler->expects($this->once())
            ->method('isLocationDataValid')
            ->with($this->defaultLocationData)
            ->will($this->returnValue(true));

        $this->jumpUrlHandler->expects($this->once())
            ->method('getResourceFactory')
            ->will($this->returnValue($resourceFactoryMock));

        self::expectException(\Exception::class);
        self::expectExceptionCode(1294585193);
        $request = $this->prepareJumpUrlSecureTest($jumpUrl, $hash);
        $this->jumpUrlHandler->process($request, $this->defaultRequestHandler);
    }

    /**
     * @test
     * @dataProvider jumpUrlSecureValidParametersDataProvider
     * @param string $hash
     * @param string $jumpUrl
     */
    public function jumpUrlSecureFailsOnDeniedAccess($hash, $jumpUrl)
    {
        $this->jumpUrlHandler->expects($this->once())
            ->method('isLocationDataValid')
            ->with($this->defaultLocationData)
            ->will($this->returnValue(false));

        self::expectException(\Exception::class);
        self::expectExceptionCode(1294585195);
        $request = $this->prepareJumpUrlSecureTest($jumpUrl, $hash);
        $this->jumpUrlHandler->process($request, $this->defaultRequestHandler);
    }

    /**
     * @test
     * @dataProvider jumpUrlSecureValidParametersDataProvider
     * @param string $hash
     * @param string $jumpUrl
     */
    public function jumpUrlSecureFailsOnInvalidHash(
        $hash,
        $jumpUrl
    ) {
        $queryParams = [
            'jumpurl' => 'random',
            'juSecure' => '1',
            'juHash' => $hash . '1',
            'locationData' => $this->defaultLocationData
        ];

        self::expectException(\Exception::class);
        self::expectExceptionCode(1294585196);
        $request = new ServerRequest('https://example.com');
        $request = $request->withQueryParams($queryParams);
        $this->jumpUrlHandler->process($request, $this->defaultRequestHandler);
    }

    /**
     * @return array
     */
    public function jumpUrlSecureFailsOnForbiddenFileLocationDataProvider()
    {
        return [
            'totally forbidden' => [
                '/a/totally/forbidden/path'
            ],
            'typo3conf file' => [
                PATH_site . '/typo3conf/path'
            ],
            'file with forbidden character' => [
                PATH_site . '/mypath/test.php'
            ]
        ];
    }

    /**
     * @test
     * @dataProvider jumpUrlSecureFailsOnForbiddenFileLocationDataProvider
     * @expectedException \Exception
     * @expectedExceptionCode 1294585194
     * @param string $path
     */
    public function jumpUrlSecureFailsOnForbiddenFileLocation($path)
    {
        $this->jumpUrlHandler->expects($this->once())
            ->method('isLocationDataValid')
            ->with('')
            ->will($this->returnValue(true));

        $hash = JumpUrlUtility::calculateHashSecure($path, '', '');

        $queryParams = [
            'jumpurl' => $path,
            'juSecure' => '1',
            'juHash' => $hash,
            'locationData' => ''
        ];
        $request = new ServerRequest('https://example.com');
        $request = $request->withQueryParams($queryParams);
        $this->jumpUrlHandler->process($request, $this->defaultRequestHandler);
    }

    /**
     * @param string $jumpurl
     * @param string $hash
     * @return ServerRequest
     */
    protected function prepareJumpUrlSecureTest(string $jumpurl, string $hash): ServerRequestInterface
    {
        $queryParams = [
            'jumpurl' => $jumpurl,
            'juSecure' => '1',
            'juHash' => $hash,
            'locationData' => $this->defaultLocationData
        ];
        $request = new ServerRequest('https://example.com/');
        return $request->withQueryParams($queryParams);
    }
}
