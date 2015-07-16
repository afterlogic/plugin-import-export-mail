/**
 * @constructor
 */
function ImportExportInfoPopup()
{
	this.fCallback = null;
	
	this.downloadButtonText = ko.observable(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/DOWNLOAD_ZIP'));
	this.laterButtonText = ko.observable(AfterLogicApi.i18n('PLUGIN_TRANSFER_MAIL/LATER'));
}
/**
 * @return {string}
 */
ImportExportInfoPopup.prototype.popupTemplate = function ()
{
	return 'Plugin_ImportExportInfoPopup';
};

/**
 * @param {Function} fCallback
 */
ImportExportInfoPopup.prototype.onShow = function (fCallback)
{
	this.fCallback = fCallback;
};

ImportExportInfoPopup.prototype.onDownloadClick = function ()
{
	if (AfterLogicApi.isFunc(this.fCallback))
	{
		this.fCallback();
		this.closeCommand();
	}
};

ImportExportInfoPopup.prototype.onLaterClick = function ()
{
	this.closeCommand();
};

ImportExportInfoPopup.prototype.onEscHandler = function ()
{
	this.onLaterClick();
};
