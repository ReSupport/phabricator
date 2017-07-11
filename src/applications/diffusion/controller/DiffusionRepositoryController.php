<?php

final class DiffusionRepositoryController extends DiffusionController {

  private $historyFuture;
  private $browseFuture;
  private $tagFuture;
  private $branchFuture;

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $response = $this->loadDiffusionContext();
    if ($response) {
      return $response;
    }

    $viewer = $this->getViewer();
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    $crumbs = $this->buildCrumbs();
    $crumbs->setBorder(true);

    $header = $this->buildHeaderView($repository);
    $curtain = $this->buildCurtain($repository);
    $property_table = $this->buildPropertiesTable($repository);
    $description = $this->buildDescriptionView($repository);
    $locate_file = $this->buildLocateFile();

    // Before we do any work, make sure we're looking at a some content: we're
    // on a valid branch, and the repository is not empty.
    $page_has_content = false;
    $empty_title = null;
    $empty_message = null;

    // If this VCS supports branches, check that the selected branch actually
    // exists.
    if ($drequest->supportsBranches()) {
      // NOTE: Mercurial may have multiple branch heads with the same name.
      $ref_cursors = id(new PhabricatorRepositoryRefCursorQuery())
        ->setViewer($viewer)
        ->withRepositoryPHIDs(array($repository->getPHID()))
        ->withRefTypes(array(PhabricatorRepositoryRefCursor::TYPE_BRANCH))
        ->withRefNames(array($drequest->getBranch()))
        ->execute();
      if ($ref_cursors) {
        // This is a valid branch, so we necessarily have some content.
        $page_has_content = true;
      } else {
        $empty_title = pht('No Such Branch');
        $empty_message = pht(
          'There is no branch named "%s" in this repository.',
          $drequest->getBranch());
      }
    }

    // If we didn't find any branches, check if there are any commits at all.
    // This can tailor the message for empty repositories.
    if (!$page_has_content) {
      $any_commit = id(new DiffusionCommitQuery())
        ->setViewer($viewer)
        ->withRepository($repository)
        ->setLimit(1)
        ->execute();
      if ($any_commit) {
        if (!$drequest->supportsBranches()) {
          $page_has_content = true;
        }
      } else {
        $empty_title = pht('Empty Repository');
        $empty_message = pht('This repository does not have any commits yet.');
      }
    }

    if ($page_has_content) {
      $content = $this->buildNormalContent($drequest);
    } else {
      $content = id(new PHUIInfoView())
        ->setTitle($empty_title)
        ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
        ->setErrors(array($empty_message));
    }

    $tabs = $this->buildTabsView('home');

    $view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setCurtain($curtain)
      ->setTabs($tabs)
      ->setMainColumn(array(
        $property_table,
        $description,
        $locate_file,
      ))
      ->setFooter($content);

    return $this->newPage()
      ->setTitle(
        array(
          $repository->getName(),
          $repository->getDisplayName(),
        ))
      ->setCrumbs($crumbs)
      ->appendChild(array(
        $view,
      ));
  }


  private function buildNormalContent(DiffusionRequest $drequest) {
    $request = $this->getRequest();
    $repository = $drequest->getRepository();

    $commit = $drequest->getCommit();
    $path = $drequest->getPath();

    $this->historyFuture = $this->callConduitMethod(
      'diffusion.historyquery',
      array(
        'commit' => $commit,
        'path' => $path,
        'offset' => 0,
        'limit' => 15,
      ));

    $browse_pager = id(new PHUIPagerView())
      ->readFromRequest($request);

    $this->browseFuture = $this->callConduitMethod(
      'diffusion.browsequery',
      array(
        'commit' => $commit,
        'path' => $path,
        'limit' => $browse_pager->getPageSize() + 1,
      ));

    $futures = array(
      $this->historyFuture,
      $this->browseFuture,
    );
    $futures = array_filter($futures);
    $futures = new FutureIterator($futures);
    foreach ($futures as $future) {
      // Just resolve all the futures before continuing.
    }

    $phids = array();
    $content = array();

    try {
      $history_results = $this->historyFuture->resolve();
      $history = DiffusionPathChange::newFromConduit(
        $history_results['pathChanges']);

      foreach ($history as $item) {
        $data = $item->getCommitData();
        if ($data) {
          if ($data->getCommitDetail('authorPHID')) {
            $phids[$data->getCommitDetail('authorPHID')] = true;
          }
          if ($data->getCommitDetail('committerPHID')) {
            $phids[$data->getCommitDetail('committerPHID')] = true;
          }
        }
      }
      $history_exception = null;
    } catch (Exception $ex) {
      $history_results = null;
      $history = null;
      $history_exception = $ex;
    }

    try {
      $browse_results = $this->browseFuture->resolve();
      $browse_results = DiffusionBrowseResultSet::newFromConduit(
        $browse_results);

      $browse_paths = $browse_results->getPaths();
      $browse_paths = $browse_pager->sliceResults($browse_paths);

      foreach ($browse_paths as $item) {
        $data = $item->getLastCommitData();
        if ($data) {
          if ($data->getCommitDetail('authorPHID')) {
            $phids[$data->getCommitDetail('authorPHID')] = true;
          }
          if ($data->getCommitDetail('committerPHID')) {
            $phids[$data->getCommitDetail('committerPHID')] = true;
          }
        }
      }

      $browse_exception = null;
    } catch (Exception $ex) {
      $browse_results = null;
      $browse_paths = null;
      $browse_exception = $ex;
    }

    $phids = array_keys($phids);
    $handles = $this->loadViewerHandles($phids);

    if ($browse_results) {
      $readme = $this->renderDirectoryReadme($browse_results);
    } else {
      $readme = null;
    }

    $content[] = $this->buildBrowseTable(
      $browse_results,
      $browse_paths,
      $browse_exception,
      $handles,
      $browse_pager);

    $content[] = $this->buildHistoryTable(
      $history_results,
      $history,
      $history_exception);

    if ($readme) {
      $content[] = $readme;
    }

    return $content;
  }

  private function buildHeaderView(PhabricatorRepository $repository) {
    $viewer = $this->getViewer();
    $header = id(new PHUIHeaderView())
      ->setHeader($repository->getName())
      ->setUser($viewer)
      ->setPolicyObject($repository)
      ->setProfileHeader(true)
      ->setImage($repository->getProfileImageURI())
      ->setImageEditURL('/diffusion/picture/'.$repository->getID().'/');

    if (!$repository->isTracked()) {
      $header->setStatus('fa-ban', 'dark', pht('Inactive'));
    } else if ($repository->isImporting()) {
      $ratio = $repository->loadImportProgress();
      $percentage = sprintf('%.2f%%', 100 * $ratio);
      $header->setStatus(
        'fa-clock-o',
        'indigo',
        pht('Importing (%s)...', $percentage));
    } else {
      $header->setStatus('fa-check', 'bluegrey', pht('Active'));
    }

    return $header;
  }

 /**
  * @phutil-external-symbol class CustomGithubDownloadLinks
  */
  private function buildCurtain(PhabricatorRepository $repository) {
    $viewer = $this->getViewer();

    $edit_uri = $repository->getPathURI('manage/');
    $curtain = $this->newCurtainView($repository);

    $curtain->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Manage Repository'))
        ->setIcon('fa-cogs')
        ->setHref($edit_uri));

    if (class_exists('CustomGithubDownloadLinks')) {
      CustomGithubDownloadLinks::AddActionLinksToCurtain(
        $repository, $repository->getDefaultBranch(), $curtain);
    }

    if ($repository->isHosted()) {
      $push_uri = $this->getApplicationURI(
        'pushlog/?repositories='.$repository->getMonogram());

      $curtain->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('View Push Logs'))
          ->setIcon('fa-list-alt')
          ->setHref($push_uri));
    }

    return $curtain;
  }

  private function buildDescriptionView(PhabricatorRepository $repository) {
    $viewer = $this->getViewer();
    $view = id(new PHUIPropertyListView())
      ->setUser($viewer);

    $description = $repository->getDetail('description');
    if (strlen($description)) {
      $description = new PHUIRemarkupView($viewer, $description);
      $view->addTextContent($description);
      return id(new PHUIObjectBoxView())
        ->setHeaderText(pht('Description'))
        ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
        ->appendChild($view);
    }
    return null;
  }

  private function buildPropertiesTable(PhabricatorRepository $repository) {
    $viewer = $this->getViewer();

    $view = id(new PHUIPropertyListView())
      ->setUser($viewer);

    $display_never = PhabricatorRepositoryURI::DISPLAY_NEVER;

    $uris = $repository->getURIs();
    foreach ($uris as $uri) {
      if ($uri->getIsDisabled()) {
        continue;
      }

      if ($uri->getEffectiveDisplayType() == $display_never) {
        continue;
      }

      if ($repository->isSVN()) {
        $label = phutil_tag_div('diffusion-clone-label', pht('Checkout'));
      } else {
        $label = phutil_tag_div('diffusion-clone-label', pht('Clone'));
      }

      $view->addProperty(
        $label,
        $this->renderCloneURI($repository, $uri));
    }

    if (!$view->hasAnyProperties()) {
      $view = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
        ->appendChild(pht('Repository has no URIs set.'));
    }

    $box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Details'))
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->appendChild($view);

    $info = null;
    $drequest = $this->getDiffusionRequest();

    // Try to load alternatives. This may fail for repositories which have not
    // cloned yet. If it does, just ignore it and continue.
    try {
      $alternatives = $drequest->getRefAlternatives();
    } catch (ConduitClientException $ex) {
      $alternatives = array();
    }

    if ($alternatives) {
      $message = array(
        pht(
          'The ref "%s" is ambiguous in this repository.',
          $drequest->getBranch()),
        ' ',
        phutil_tag(
          'a',
          array(
            'href' => $drequest->generateURI(
              array(
                'action' => 'refs',
              )),
          ),
          pht('View Alternatives')),
      );

      $messages = array($message);

      $info = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
        ->setErrors(array($message));

      $box->setInfoView($info);
    }


    return $box;
  }

  private function buildHistoryTable(
    $history_results,
    $history,
    $history_exception) {

    $request = $this->getRequest();
    $viewer = $request->getUser();
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    if ($history_exception) {
      if ($repository->isImporting()) {
        return $this->renderStatusMessage(
          pht('Still Importing...'),
          pht(
            'This repository is still importing. History is not yet '.
            'available.'));
      } else {
        return $this->renderStatusMessage(
          pht('Unable to Retrieve History'),
          $history_exception->getMessage());
      }
    }

    $history_table = id(new DiffusionHistoryTableView())
      ->setUser($viewer)
      ->setDiffusionRequest($drequest)
      ->setHistory($history);

    // TODO: Super sketchy.
    $history_table->loadRevisions();

    if ($history_results) {
      $history_table->setParents($history_results['parents']);
    }

    $history_table->setIsHead(true);

    $panel = id(new PHUIObjectBoxView())
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY);
    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Recent Commits'));
    $panel->setHeader($header);
    $panel->setTable($history_table);

    return $panel;
  }

  private function buildLocateFile() {
    $request = $this->getRequest();
    $viewer = $request->getUser();
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    $locate_panel = null;
    if ($repository->canUsePathTree()) {
      Javelin::initBehavior(
        'diffusion-locate-file',
        array(
          'controlID' => 'locate-control',
          'inputID' => 'locate-input',
          'browseBaseURI' => (string)$drequest->generateURI(
            array(
              'action' => 'browse',
            )),
          'uri' => (string)$drequest->generateURI(
            array(
              'action' => 'pathtree',
            )),
        ));

      $form = id(new AphrontFormView())
        ->setUser($viewer)
        ->appendChild(
          id(new AphrontFormTypeaheadControl())
            ->setHardpointID('locate-control')
            ->setID('locate-input')
            ->setLabel(pht('Locate File')));
      $form_box = id(new PHUIBoxView())
        ->appendChild($form->buildLayoutView());
      $locate_panel = id(new PHUIObjectBoxView())
        ->setHeaderText(pht('Locate File'))
        ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
        ->appendChild($form_box);
    }
    return $locate_panel;
  }

  private function buildBrowseTable(
    $browse_results,
    $browse_paths,
    $browse_exception,
    array $handles,
    PHUIPagerView $pager) {

    require_celerity_resource('diffusion-icons-css');

    $request = $this->getRequest();
    $viewer = $request->getUser();
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    if ($browse_exception) {
      if ($repository->isImporting()) {
        // The history table renders a useful message.
        return null;
      } else {
        return $this->renderStatusMessage(
          pht('Unable to Retrieve Paths'),
          $browse_exception->getMessage());
      }
    }

    $browse_table = id(new DiffusionBrowseTableView())
      ->setUser($viewer)
      ->setDiffusionRequest($drequest)
      ->setHandles($handles);
    if ($browse_paths) {
      $browse_table->setPaths($browse_paths);
    } else {
      $browse_table->setPaths(array());
    }

    $browse_uri = $drequest->generateURI(array('action' => 'browse'));

    $browse_panel = id(new PHUIObjectBoxView())
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY);
    $header = id(new PHUIHeaderView())
      ->setHeader($repository->getName());

    $button = id(new PHUIButtonView())
      ->setText(pht('Browse'))
      ->setTag('a')
      ->setIcon('fa-code')
      ->setHref($browse_uri);

    $header->addActionLink($button);
    $browse_panel->setHeader($header);
    $browse_panel->setTable($browse_table);

    $pager->setURI($browse_uri, 'offset');

    if ($pager->willShowPagingControls()) {
      $browse_panel->setPager($pager);
    }

    return $browse_panel;
  }

  private function renderCloneURI(
    PhabricatorRepository $repository,
    PhabricatorRepositoryURI $uri) {

    if ($repository->isSVN()) {
      $display = csprintf(
        'svn checkout %R %R',
        (string)$uri->getDisplayURI(),
        $repository->getCloneName());
    } else {
      $display = csprintf('%R', (string)$uri->getDisplayURI());
    }

    $display = (string)$display;
    $viewer = $this->getViewer();

    return id(new DiffusionCloneURIView())
      ->setViewer($viewer)
      ->setRepository($repository)
      ->setRepositoryURI($uri)
      ->setDisplayURI($display);
  }

  private function getTagLimit() {
    return 15;
  }

}
