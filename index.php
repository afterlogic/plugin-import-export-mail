<?php

/* -AFTERLOGIC LICENSE HEADER- */

class_exists('CApi') or die();

include_once 'libs/ZipStream/ZipStream.php';
include_once 'libs/ZipStream/Exception.php';
include_once 'libs/ZipStream/Exception/FileNotFoundException.php';
include_once 'libs/ZipStream/Exception/FileNotReadableException.php';
include_once 'libs/ZipStream/Exception/InvalidOptionException.php';

class CImportExportMailPlugin extends AApiPlugin
{
	/**
	 * @param CApiPluginManager $oPluginManager
	 */
	
	public $oLogPrefix = 'plugin-import-export-mail-';
	public static $bUseLog = true;
	
	public function __construct(CApiPluginManager $oPluginManager)
	{
		parent::__construct('1.0', $oPluginManager);
		
		$this->AddJsonHook('AjaxExportMailStatus', 'AjaxExportMailStatus');
		$this->AddJsonHook('AjaxExportMailPrepare', 'AjaxExportMailPrepare');
		$this->AddJsonHook('AjaxExportMailGenerate', 'AjaxExportMailGenerate');
 	}

	public function Init()
	{
		parent::Init();

		$this->SetI18N(true);

		$this->AddJsFile('js/include.js');
		$this->AddJsFile('js/ImportExportPopup.js');
		$this->AddJsFile('js/ImportExportInfoPopup.js');

		$this->IncludeTemplate('Settings_AccountFoldersViewModel', 'Account-Folders-After-Buttons', 'templates/index.html');
		$this->AddTemplate('ImportExportPopup', 'templates/ImportExportPopup.html', 'Layout', 'Screens-Middle', 'popup folders_setup');
		$this->AddTemplate('ImportExportInfoPopup', 'templates/ImportExportInfoPopup.html', 'Layout', 'Screens-Middle', 'popup folders_setup');
		$this->AddServiceHook('transfer-mail', 'ServiceHook');
	}
	
	public function Log($mLog, $bIsException = false, $bIsObject = false)
	{
		if (self::$bUseLog)
		{
			if ($bIsException)
			{
				\CApi::LogException($mLog, \ELogLevel::Full, $this->oLogPrefix);
			}
			else if ($bIsObject)
			{
				\CApi::LogObject($mLog, \ELogLevel::Full, $this->oLogPrefix);
			}
			else
			{
				\CApi::Log($mLog, \ELogLevel::Full, $this->oLogPrefix);
			}
		}
	}

	/* 
	 * @return \CApiIntegratorManager
	 */
	private function oApiIntegratorManager()
	{
		static $oApiIntegrator = null;
		if (null === $oApiIntegrator)
		{
			$oApiIntegrator = CApi::Manager('integrator');
		}
		
		return $oApiIntegrator;
	}

	/* 
	 * @return \CApiMailManager
	 */
	private function oApiMailManager()
	{
		static $oApiMail = null;
		if (null === $oApiMail)
		{
			$oApiMail = CApi::Manager('mail');
		}
		
		return $oApiMail;
	}

	/* 
	 * @return \CApiUsersManager
	 */
	private function oApiUsersManager()
	{
		static $oApiUsers = null;
		if (null === $oApiUsers)
		{
			$oApiUsers = CApi::Manager('users');
		}
		
		return $oApiUsers;
	}
	
	/* 
	 * @return \CApiFilecacheManager
	 */
	public function oApiFileCacheManager()
	{
		static $oApiFileCache = null;
		if (null === $oApiFileCache)
		{
			$oApiFileCache = CApi::Manager('filecache');
		}
		
		return $oApiFileCache;
	}

	public function ServiceHook($sName = '', $sType = '', $sFolder = '', $sAccountId = '')
	{
		$mResult = array();

		/* @var $oApiUsers \CApiUsersManager */
		$oApiUsers = $this->oApiUsersManager();

		/* @var $oApiIntegrator \CApiIntegratorManager */
		$oApiIntegrator = $this->oApiIntegratorManager();

		$iAccountId = (int) isset($sAccountId) ? $sAccountId : 0;

		$oDefAccount = $oApiIntegrator->getLogginedDefaultAccount();
		$oAccount = $oApiUsers->getAccountById($iAccountId);
		if ($oDefAccount)
		{
			if ($sType === 'export')
			{
				$this->ExportMail($oDefAccount, $sFolder);
			}
			else if ($sType === 'import' && $oAccount && $oAccount->IdUser === $oDefAccount->IdUser)
			{
				$this->ImportMail($oDefAccount, $oAccount, str_replace('..', '/', $sFolder));
			}
		}
	}
	
	/**
	 * @param \Core\Actions $oServer
	 * @return type
	 */
	public function AjaxExportMailStatus($oServer)
	{
		$mResult['Result'] = false;
		try
		{
			$oDefAccount = $oServer->GetDefaultAccount();

			if ($oDefAccount)
			{
				$sZipName = $oServer->getParamValue('Zip', null);
				$oDefAccount = $oServer->GetDefaultAccount();
				if ($this->oApiFileCacheManager()->isFileExists($oDefAccount, $sZipName, '.info'))
				{
					$mResult['Result'] = array(
						'Status' => $this->oApiFileCacheManager()->get($oDefAccount, $sZipName, '.info')
					);
				}
			}
		}
		catch (Exception $oEx)
		{
			$mResult['Result'] = false;
			$mResult['ErrorMessage'] = $oEx->getMessage();
		}
		return $mResult;
	}

	/**
	 * @param \Core\Actions $oServer
	 * @return type
	 */
	public function AjaxExportMailPrepare($oServer)
	{
		$mResult['Result'] = false;
		try
		{
			$oDefAccount = $oServer->GetDefaultAccount();

			if ($oDefAccount)
			{
				$sZipName = \md5(\time().\rand(1000, 9999));
				$mResult['Result'] = array(
					'Zip' => $sZipName, 
				);

				$this->oApiFileCacheManager()->put($oDefAccount, $sZipName, '', '.zip');
				$this->oApiFileCacheManager()->put($oDefAccount, $sZipName, 'prepare', '.info');
			}
		}
		catch (Exception $oEx)
		{
			$mResult['Result'] = false;
			$mResult['ErrorMessage'] = $oEx->getMessage();
		}
		return $mResult;
	}
	
	/**
	 * @param \ProjectCore\Actions $oServer
	 * @return type
	 */
	public function AjaxExportMailGenerate($oServer)
	{
		$oDefAccount = $oServer->GetDefaultAccount();
		$sZipName = $oServer->getParamValue('Zip', null);
		try
		{
			$aTempFiles = array();

			$sFolder = $oServer->getParamValue('Folder', null);
			$this->oApiFileCacheManager()->put($oDefAccount, $sZipName, 'generate', '.info');

			/* @var $oApiUsers \CApiUsersManager */
			$oApiUsers = $this->oApiUsersManager();
			$iAccountId = (int) $oServer->getParamValue('Account', 0);
			$oAccount = $oApiUsers->getAccountById($iAccountId);

			if ($oAccount && $oDefAccount && $oAccount->IdUser === $oDefAccount->IdUser && $sFolder !== null && $sZipName !== null)
			{
				/* @var $oApiMail \CApiMailManager */
				$oApiMail = $this->oApiMailManager();

				$iOffset = 0;
				$iLimit = 20;

				$sZipFilePath = $this->oApiFileCacheManager()->generateFullFilePath($oDefAccount, $sZipName, '.zip');
				$rZipResource = fopen($sZipFilePath, 'w+b');

				$oZip = new \ZipStream\ZipStream(null, array(\ZipStream\ZipStream::OPTION_OUTPUT_STREAM => $rZipResource));
				$this->Log('Start fetching mail');

				$self = $this;

				$aData = $oApiMail->getFolderInformation($oAccount, $sFolder);
				$iCount = (is_array($aData) && 4 === count($aData)) ? $aData[0] : 0;

				while ($iOffset <= $iCount)
				{
					/* @var $oMessageListCollection \CApiMailMessageCollection */
					$oMessageListCollection =  $oApiMail->getMessageList($oAccount, $sFolder, $iOffset, $iLimit);
					$oMessageListCollection->ForeachList(function (/* @var $oMessage \CApiMailMessage */ $oMessage) use ($oApiMail, $oDefAccount, $oAccount, $self, $sFolder, &$oZip, &$mResult, &$aTempFiles) {
						$iUid = $oMessage->getUid();
						$oApiMail->directMessageToStream($oAccount,
							function($rResource, $sContentType, $sFileName, $sMimeIndex = '') use ($oDefAccount, &$mResult, $self, $iUid, &$oZip, &$aTempFiles) {

								$sTempName = \md5(\time().\rand(1000, 9999).$sFileName);
								$aTempFiles[] = $sTempName;

								if (is_resource($rResource) && $self->oApiFileCacheManager()->putFile($oDefAccount, $sTempName, $rResource))
								{
									$sFilePath = $self->oApiFileCacheManager()->generateFullFilePath($oDefAccount, $sTempName);
									$rSubResource = fopen($sFilePath, 'rb');
									if (is_resource($rSubResource))
									{
										$sFileName = 'uid-' . $iUid . '.eml';
										$self->Log('Append file \'' . $sFileName . '\' to ZIP');
										$oZip->addFileFromStream($sFileName, $rSubResource);
										$MemoryUsage = memory_get_usage(true)/(1024*1024);
										$self->Log('Memory usage: ' . $MemoryUsage);
										@fclose($rSubResource);
									}
								}
							}, $sFolder, $iUid);	
					});					
					$iOffset = $iOffset + $iLimit;
				}
				$this->Log('End fetching mail');
				$oZip->finish();
				$this->Log('Create ZIP file');
				foreach ($aTempFiles as $sTempName)
				{
					$this->Log('Remove temp file: ' . $sTempName);
					$self->oApiFileCacheManager()->clear($oDefAccount, $sTempName);				
				}
			}
			$this->Log('Generating ZIP Result: ');
			$this->Log($mResult, false, true);
			$this->oApiFileCacheManager()->put($oDefAccount, $sZipName, 'ready', '.info');
		}
		catch (Exception $oEx)
		{
			$this->oApiFileCacheManager()->put($oDefAccount, $sZipName, 'error', '.info');
			$this->Log($oEx, true);
		}
	}	
	
	public function ExportMail($oDefAccount, $sZipFile)
	{
		$this->oApiFileCacheManager()->put($oDefAccount, $sZipFile, 'download', '.info');
		$this->Log('Start downloading ZIP file.. ');

		$sZipFilePath = $this->oApiFileCacheManager()->generateFullFilePath($oDefAccount, $sZipFile, '.zip');
		$iFileSize = filesize($sZipFilePath);
		$this->Log('ZIP file size: ' . $iFileSize);
		
		header("Content-type: application/zip"); 
		header("Content-Disposition: attachment; filename=export-mail.zip"); 
		header('Accept-Ranges: none', true);
		header('Content-Transfer-Encoding: binary');				
		header("Content-Length: " . $iFileSize); 
		header("Pragma: no-cache"); 
		header("Expires: 0"); 
		
		$self = $this;

		$rZipResource = fopen($sZipFilePath, 'rb');
		if ($rZipResource !== false)
		{
			$this->Log('Start write data to buffer');
			rewind($rZipResource);
			while (!\feof($rZipResource))
			{
				$MemoryUsage = memory_get_usage(true)/(1024*1024);
				$this->Log('Write data to buffer - memory usage:' . $MemoryUsage);
				$sBuffer = @\fread($rZipResource, 8192);
				if (false !== $sBuffer)
				{
					echo $sBuffer;
					ob_flush();
					flush();
					\MailSo\Base\Utils::ResetTimeLimit();
					continue;
				}
				break;
			}
			@fclose($rZipResource);
			$this->Log('End write data to buffer');
		}				
//		$self->oApiFileCacheManager()->Clear($oDefAccount, $sZipFile, '.zip');				
//		$self->oApiFileCacheManager()->Clear($oDefAccount, $sZipFile, '.info');				
		$this->Log('Finish ZIP file downloading');
		exit;
	}
	
	public function ImportMail($oDefAccount, $oAccount, $sFolder)
	{
		@ob_start();
		$aResponseItem = array();
		try
		{
			$sError = '';
			$sInputName = 'jua-uploader';

			$iError = UPLOAD_ERR_OK;
			$_FILES = isset($_FILES) ? $_FILES : null;
			if (isset($_FILES, $_FILES[$sInputName], $_FILES[$sInputName]['name'], $_FILES[$sInputName]['tmp_name'], $_FILES[$sInputName]['size'], $_FILES[$sInputName]['type']))
			{
				$iError = (isset($_FILES[$sInputName]['error'])) ? (int) $_FILES[$sInputName]['error'] : UPLOAD_ERR_OK;
				if (UPLOAD_ERR_OK === $iError)
				{
					$aFileData = $_FILES[$sInputName];
					if (is_array($aFileData))
					{
						$sUploadName = $aFileData['name'];
						$bIsZipExtension  = strtolower(pathinfo($sUploadName, PATHINFO_EXTENSION)) === 'zip';

						if ($bIsZipExtension) 
						{
							$sSavedName = 'upload-post-' . md5($aFileData['name'] . $aFileData['tmp_name']);
							if ($this->oApiFileCacheManager()->moveUploadedFile($oDefAccount, $sSavedName, $aFileData['tmp_name'])) 
							{
								$sSavedFullName = $this->oApiFileCacheManager()->generateFullFilePath($oDefAccount, $sSavedName);

								/* @var $oApiMail \CApiMailManager */
								$oApiMail = $this->oApiMailManager();
								
								$oZip = new ZipArchive();
								if ($oZip->open($sSavedFullName)) 
								{ 
									for($i = 0; $i < $oZip->numFiles; $i++) 
									{   
										$sFileName = $oZip->getNameIndex($i);
										if (strtolower(pathinfo($sFileName, PATHINFO_EXTENSION)) === 'eml')
										{
											$aFileParams = $oZip->statIndex($i);		
											$iStreamSize = $aFileParams['size'];
											$rMessage = $oZip->getStream($sFileName);
											if (is_resource($rMessage))
											{
												$oApiMail->appendMessageFromStream($oAccount, $rMessage, $sFolder, $iStreamSize);
												@fclose($rMessage);
											}
										}
									}
									$oZip->close();
								} 								
							} 
							else 
							{
								$sError = 'unknown';
							}
						}
						else
						{
							$sError = 'incorrect file extension';
						}
					}
				}
				else
				{
					$sError = 'unknown';
				}
			}
			else if (!isset($_FILES) || !is_array($_FILES) || 0 === count($_FILES))
			{
				$sError = 'size';
			}
			else
			{
				$sError = 'unknown';
			}
		}
		catch (\Exception $oEx)
		{
			$this->Log($oEx, true);
			$sError = 'exception: ' . $oEx->getMessage();
		}

		if (0 < strlen($sError))
		{
			$aResponseItem['Error'] = $sError;
		}

		@ob_get_clean();
		@header('Content-Type: text/html; charset=utf-8');

		echo \MailSo\Base\Utils::Php2js($aResponseItem);
	}	
	
	/**
	 * @param string $sFileName
	 * @param string $sContentType
	 * @param string $sMimeIndex = ''
	 *
	 * @return string
	 */
	public function clearFileName($sFileName, $sContentType, $sMimeIndex = '')
	{
		$sFileName = 0 === strlen($sFileName) ? preg_replace('/[^a-zA-Z0-9]/', '.', (empty($sMimeIndex) ? '' : $sMimeIndex.'.').$sContentType) : $sFileName;
		$sClearedFileName = preg_replace('/[\s]+/', ' ', preg_replace('/[\.]+/', '.', $sFileName));
		$sExt = \MailSo\Base\Utils::GetFileExtension($sClearedFileName);

		$iSize = 100;
		if ($iSize < strlen($sClearedFileName) - strlen($sExt))
		{
			$sClearedFileName = substr($sClearedFileName, 0, $iSize).(empty($sExt) ? '' : '.'.$sExt);
		}

		return \MailSo\Base\Utils::ClearFileName(\MailSo\Base\Utils::Utf8Clear($sClearedFileName));
	}	
}

return new CImportExportMailPlugin($this);
