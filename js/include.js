(function () {

	AfterLogicApi.addPluginHook('view-model-defined', function (sViewModelName, oViewModel) {

		if (oViewModel && ('CAccountFoldersViewModel' === sViewModelName))
		{
			oViewModel.folderTransferClick = function () {
				AfterLogicApi.showPopup(ImportExportPopup);
			};
		}
	});
	AfterLogicApi.addPluginHook('ajax-default-request', function (sAction, oParameters) {

	});

	AfterLogicApi.addPluginHook('ajax-default-response', function (sAction, oData) {

	});	

}());