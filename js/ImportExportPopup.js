/**
 * @constructor
 */
function ImportExportPopup()
{
	this.zipFile = ko.observable(null);
	
	this.status = ko.observable('');
	this.status.subscribe(function(bValue) {
		if (this.oJua)
		{
			this.oJua.bEnableButton = (bValue === '' || bValue === 'ready' || bValue === 'error');
		}
		if (bValue === 'ready')
		{
			if (!this.opened())
			{
				AfterLogicApi.showPopup(ImportExportInfoPopup, [_.bind(this.Download, this)]);
			}
			this.exportButtonText(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/DOWNLOAD_ZIP'));
			clearTimeout(this.iAutoReloadTimer);
		}
		else if (bValue === 'error')
		{
			AfterLogicApi.showError(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/ERROR_GENERATE_ZIP'));
			this.exportButtonText(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/EXPORT_MAIL'));
			clearTimeout(this.iAutoReloadTimer);
		}
	}, this);

	this.processing = ko.computed(function() {
        return (this.status() !== 'ready' && this.status() !== 'error' && this.status() !== '');
    }, this);

	this.fCallback = null;
	
	this.importButtonText = ko.observable(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/IMPORT_MAIL'));
	this.exportButtonText = ko.observable(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/EXPORT_MAIL'));
	
	this.folder = ko.observable('');

	this.folders = AfterLogicApi.editedFolderList();

	this.options = ko.computed(function(){
		if (this.folders() !== null)
		{
			return this.folders().getOptions('', true, false, true);
		}
	}, this);
	
	// file uploader
	this.oJua = null;
	
	this.uploaderButton = ko.observable(null);
	this.folders.subscribe(function () {
		this.initUploader();
	}, this);
	this.iAutoReloadTimer = -1;
	this.opened = ko.observable(false);
}
/**
 * @return {string}
 */
ImportExportPopup.prototype.popupTemplate = function ()
{
	return 'Plugin_ImportExportPopup';
};

ImportExportPopup.prototype.setAutoReloadTimer = function ()
{
	var self = this;
	clearTimeout(this.iAutoReloadTimer);
	
	this.iAutoReloadTimer = setTimeout(function () {
		self.Status();
	}, 10 * 1000);
};

/**
 * @param {Function} fCallback
 */
ImportExportPopup.prototype.onShow = function (fCallback)
{
	this.fCallback = fCallback;
	this.opened(true);
	this.initUploader();
};

ImportExportPopup.prototype.Status = function ()
{
	this.setAutoReloadTimer();
	if (this.status() !== '')
	{
		App.Ajax.send({
				'Action': 'ExportMailStatus',
				'Zip': this.zipFile()
			},
			this.StatusResponse,
			this
		);
	}
};

ImportExportPopup.prototype.StatusResponse = function (oResponse)
{
	if (oResponse.Result)
	{
		this.status(oResponse.Result.Status);
	}
};

ImportExportPopup.prototype.onExportClick = function ()
{
	if (this.status() === '')
	{
		this.status('prepare');
		App.Ajax.send({
				'Action': 'ExportMailPrepare'
			},
			this.Generate,
			this
		);
		this.setAutoReloadTimer();
	}
	else if (this.status() === 'ready')
	{
		this.Download();
	}
};

ImportExportPopup.prototype.Generate = function (oResponse)
{
	var 
		oAccountData =  AfterLogicApi.getCurrentAccountData()
	;
	if (oResponse.Result && this.status() === 'prepare')
	{
		this.zipFile(oResponse.Result.Zip);
		this.exportButtonText(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/GENERATING_ZIP'));
		App.Ajax.send({
				'Action': 'ExportMailGenerate',
				'Account': oAccountData.Id,
				'Folder': this.folder(),
				'Zip': this.zipFile()
			}
		);
	}
};

ImportExportPopup.prototype.Download = function ()
{
	if (this.status() === 'ready')
	{
		this.status('');
		this.exportButtonText(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/EXPORT_MAIL'));
		window.location.href = '?transfer-mail/export/' + this.zipFile();
	}
};

ImportExportPopup.prototype.onCancelClick = function ()
{
	this.opened(false);
	this.closeCommand();
};

ImportExportPopup.prototype.onEscHandler = function ()
{
	this.onCancelClick();
};

/**
 * Initializes file uploader.
 */
ImportExportPopup.prototype.initUploader = function ()
{
	var 
		oAccountData =  AfterLogicApi.getCurrentAccountData()
	;
	if (this.uploaderButton() && this.oJua === null)
	{
		this.oJua = new Jua({
			'action': '?/transfer-mail/import/' + this.folder() + '/' + oAccountData.Id,
			'name': 'jua-uploader',
			'queueSize': 1,
			'clickElement': this.uploaderButton(),
			'disableAjaxUpload': false,
			'disableFolderDragAndDrop': true,
			'disableDragAndDrop': true
		});

		this.oJua.bEnableButton = true;
		this.oJua
			.on('onSelect', _.bind(this.onFileUploadSelect, this))
			.on('onStart', _.bind(this.onFileUploadStart, this))
			.on('onComplete', _.bind(this.onFileUploadComplete, this))
		;
	}
};

ImportExportPopup.prototype.onFileUploadSelect = function (sFileUid, oFileData)
{
	var 
		oAccountData =  AfterLogicApi.getCurrentAccountData(),
		sFolder = this.folder().replace('/', '..')
	;
	if (AfterLogicApi.FileSizeLimit > 0 && oFileData.Size/(1024*1024) > AfterLogicApi.FileSizeLimit)
	{
		AfterLogicApi.showPopup(AlertPopup, [
			Utils.i18n('FILESTORAGE/ERROR_SIZE_LIMIT', {'SIZE': AfterLogicApi.FileSizeLimit})
		]);
		return false;
	}	
			
	this.oJua.setOption('action', '?/transfer-mail/import/' + sFolder + '/' + oAccountData.Id);
};

ImportExportPopup.prototype.onFileUploadStart = function ()
{
	this.status('upload');
	this.importButtonText(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/IMPORTING_ZIP'));
};

ImportExportPopup.prototype.onFileUploadComplete = function (sFileUid, bResult, oResult)
{
	this.status('');
	this.importButtonText(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/IMPORT_MAIL'));
	if (bResult)
	{
		if (oResult.Error)
		{
			AfterLogicApi.showError('ZIP file upload failed with error: ' + oResult.Error);
		}
		else
		{
			AfterLogicApi.showReport('ZIP file upload successfully completed', 0);
		}
	}
	else
	{
		AfterLogicApi.showError('ZIP file upload failed with error: ' + 'unknown');
	}
};

