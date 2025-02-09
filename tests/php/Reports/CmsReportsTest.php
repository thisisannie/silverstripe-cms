<?php

namespace SilverStripe\CMS\Tests\Reports;

use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\CMS\Reports\BrokenFilesReport;
use SilverStripe\CMS\Reports\BrokenLinksReport;
use SilverStripe\CMS\Reports\BrokenRedirectorPagesReport;
use SilverStripe\CMS\Reports\BrokenVirtualPagesReport;
use SilverStripe\CMS\Reports\RecentlyEditedReport;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Reports\Report;

class CmsReportsTest extends SapphireTest
{
    protected static $fixture_file = 'CmsReportsTest.yml';

    private static $daysAgo = 14;

    protected function setUp(): void
    {
        parent::setUp();

        // set the dates by hand: impossible to set via yml
        $afterThreshold = strtotime('-' . (CmsReportsTest::$daysAgo - 1) . ' days', strtotime('31-06-2009 00:00:00'));
        $beforeThreshold = strtotime('-' . (CmsReportsTest::$daysAgo + 1) . ' days', strtotime('31-06-2009 00:00:00'));

        $after = $this->objFromFixture(SiteTree::class, 'after');
        $before = $this->objFromFixture(SiteTree::class, 'before');
        DB::query(
            "UPDATE \"SiteTree\" SET \"Created\"='2009-01-01 00:00:00', \"LastEdited\"='" .
            date('Y-m-d H:i:s', $afterThreshold) . "' WHERE \"ID\"='" . $after->ID . "'"
        );
        DB::query(
            "UPDATE \"SiteTree\" SET \"Created\"='2009-01-01 00:00:00', \"LastEdited\"='" .
            date('Y-m-d H:i:s', $beforeThreshold) . "' WHERE \"ID\"='" . $before->ID . "'"
        );
    }

    /**
     *  ASSERT whether a report is returning the correct results, based on a broken "draft" and/or "published" page.
     *
     * @param Report $report
     * @param bool $isDraftBroken
     * @param bool $isPublishedBroken
     */
    public function isReportBroken($report, $isDraftBroken, $isPublishedBroken)
    {
        $class = get_class($report);

        // ASSERT that the "draft" report is returning the correct results.
        $parameters = ['CheckSite' => 'Draft'];
        $results = count($report->sourceRecords($parameters, null, null)) > 0;
        $isDraftBroken
            ? $this->assertTrue(
                $results,
                "{$class} has NOT returned the correct DRAFT results, as NO pages were found."
            ) : $this->assertFalse(
                $results,
                "{$class} has NOT returned the correct DRAFT results, as pages were found."
            );

        // ASSERT that the "published" report is returning the correct results.
        $parameters = ['CheckSite' => 'Published', 'OnLive' => 1];
        $results = count($report->sourceRecords($parameters, null, null) ?? []) > 0;
        $isPublishedBroken
            ? $this->assertTrue(
                $results,
                "{$class} has NOT returned the correct PUBLISHED results, as NO pages were found."
            ) : $this->assertFalse(
                $results,
                "{$class} has NOT returned the correct PUBLISHED results, as pages were found."
            );
    }

    public function testRecentlyEdited()
    {
        DBDatetime::set_mock_now('2009-06-30 00:00:00');

        $after = $this->objFromFixture(SiteTree::class, 'after');
        $before = $this->objFromFixture(SiteTree::class, 'before');

        $r = new RecentlyEditedReport();

        // check if contains only elements not older than $daysAgo days
        $this->assertNotNull($r->records([]));
        $this->assertContains($after->ID, $r->records([])->column('ID'));
        $this->assertNotContains($before->ID, $r->records([])->column('ID'));

        DBDatetime::clear_mock_now();
    }

    /**
     *  Test the broken links side report.
     */
    public function testBrokenLinks()
    {
        // Create a "draft" page with a broken link.
        $page = new SiteTree();
        $page->Content = "<a href='[sitetree_link,id=987654321]'>This</a> is a broken link.";
        $page->writeToStage('Stage');

        // Retrieve the broken links side report.
        $reports = Report::get_reports();
        $brokenLinksReport = null;
        foreach ($reports as $report) {
            if ($report instanceof BrokenLinksReport) {
                $brokenLinksReport = $report;
                break;
            }
        }

        // Determine that the report exists, otherwise it has been excluded.
        if (!$brokenLinksReport) {
            $this->markTestSkipped('BrokenLinksReport is not an available report');
            return;
        }

        // ASSERT that the "draft" report has detected the page having a broken link.
        // ASSERT that the "published" report has NOT detected the page having a broken link,
        // as the page has not been "published" yet.
        $this->isReportBroken($brokenLinksReport, true, false);

        // Make sure the page is now "published".
        $page->writeToStage('Live');

        // ASSERT that the "draft" report has detected the page having a broken link.
        // ASSERT that the "published" report has detected the page having a broken link.

        $this->isReportBroken($brokenLinksReport, true, true);

        // Correct the "draft" broken link.
        $page->Content = str_replace('987654321', $page->ID ?? '', $page->Content ?? '');
        $page->writeToStage('Stage');

        // ASSERT that the "draft" report has NOT detected the page having a broken link.
        // ASSERT that the "published" report has detected the page having a broken link,
        // as the previous content remains "published".
        $this->isReportBroken($brokenLinksReport, false, true);

        // Make sure the change has now been "published".
        $page->writeToStage('Live');

        // ASSERT that the "draft" report has NOT detected the page having a broken link.
        // ASSERT that the "published" report has NOT detected the page having a broken link.

        $this->isReportBroken($brokenLinksReport, false, false);
    }

    /**
     *  Test the broken files side report.
     */
    public function testBrokenFiles()
    {
        // Create a "draft" page with a broken file.
        $page = new SiteTree();
        $page->Content = "<a href='[file_link,id=987654321]'>This</a> is a broken file.";
        $page->writeToStage('Stage');

        // Retrieve the broken files side report.
        $reports = Report::get_reports();
        $brokenFilesReport = null;
        foreach ($reports as $report) {
            if ($report instanceof BrokenFilesReport) {
                $brokenFilesReport = $report;
                break;
            }
        }

        // Determine that the report exists, otherwise it has been excluded.
        if (!$brokenFilesReport) {
            $this->markTestSkipped('BrokenFilesReport is not an available report');
            return;
        }

        // ASSERT that the "draft" report has detected the page having a broken file.
        // ASSERT that the "published" report has NOT detected the page having a broken file,
        // as the page has not been "published" yet.
        $this->isReportBroken($brokenFilesReport, true, false);

        // Make sure the page is now "published".
        $page->writeToStage('Live');

        // ASSERT that the "draft" report has detected the page having a broken file.
        // ASSERT that the "published" report has detected the page having a broken file.
        $this->isReportBroken($brokenFilesReport, true, true);

        // Correct the "draft" broken file.
        $file = File::create();
        $file->Filename = 'name.pdf';
        $file->write();
        $page->Content = str_replace('987654321', $file->ID ?? '', $page->Content ?? '');
        $page->writeToStage('Stage');

        // ASSERT that the "draft" report has NOT detected the page having a broken file.
        // ASSERT that the "published" report has detected the page having a broken file,
        // as the previous content remains "published".
        $this->isReportBroken($brokenFilesReport, false, true);

        // Make sure the change has now been "published".
        $page->writeToStage('Live');

        // ASSERT that the "draft" report has NOT detected the page having a broken file.
        // ASSERT that the "published" report has NOT detected the page having a broken file.
        $this->isReportBroken($brokenFilesReport, false, false);
    }

    /**
     *  Test the broken virtual pages side report.
     */

    public function testBrokenVirtualPages()
    {
        // Create a "draft" virtual page with a broken link.
        $page = VirtualPage::create();
        $page->CopyContentFromID = 987654321;
        $page->writeToStage('Stage');

        // Retrieve the broken virtual pages side report.
        $reports = Report::get_reports();
        $brokenVirtualPagesReport = null;
        foreach ($reports as $report) {
            if ($report instanceof BrokenVirtualPagesReport) {
                $brokenVirtualPagesReport = $report;
                break;
            }
        }

        // Determine that the report exists, otherwise it has been excluded.
        if (!$brokenVirtualPagesReport) {
            $this->markTestSkipped('BrokenFilesReport is not an available report');
            return;
        }

        // ASSERT that the "draft" report has detected the page having a broken link.
        // ASSERT that the "published" report has NOT detected the page having a broken link,
        // as the page has not been "published" yet.
        $this->isReportBroken($brokenVirtualPagesReport, true, false);

        // Make sure the page is now "published".
        $page->writeToStage('Live');

        // ASSERT that the "draft" report has detected the page having a broken link.
        // ASSERT that the "published" report has detected the page having a broken link.
        $this->isReportBroken($brokenVirtualPagesReport, true, true);

        // Correct the "draft" broken link.
        $contentPage = new SiteTree();
        $contentPage->Content = 'This is some content.';
        $contentPage->writeToStage('Stage');
        $contentPage->writeToStage('Live');
        $page->CopyContentFromID = $contentPage->ID;
        $page->writeToStage('Stage');

        // ASSERT that the "draft" report has NOT detected the page having a broken link.
        // ASSERT that the "published" report has detected the page having a broken link,
        // as the previous content remains "published".
        $this->isReportBroken($brokenVirtualPagesReport, false, true);

        // Make sure the change has now been "published".
        $page->writeToStage('Live');

        // ASSERT that the "draft" report has NOT detected the page having a broken link.
        // ASSERT that the "published" report has NOT detected the page having a broken link.
        $this->isReportBroken($brokenVirtualPagesReport, false, false);
    }

    /**
     *  Test the broken redirector pages side report.
     */
    public function testBrokenRedirectorPages()
    {
        // Create a "draft" redirector page with a broken link.
        $page = RedirectorPage::create();
        $page->RedirectionType = 'Internal';
        $page->LinkToID = 987654321;
        $page->writeToStage('Stage');

        // Retrieve the broken redirector pages side report.
        $reports = Report::get_reports();
        $brokenRedirectorPagesReport = null;
        foreach ($reports as $report) {
            if ($report instanceof BrokenRedirectorPagesReport) {
                $brokenRedirectorPagesReport = $report;
                break;
            }
        }

        // Determine that the report exists, otherwise it has been excluded.
        if (!$brokenRedirectorPagesReport) {
            $this->markTestSkipped('BrokenRedirectorPagesReport is not an available report');
            return;
        }

        // ASSERT that the "draft" report has detected the page having a broken link.
        // ASSERT that the "published" report has NOT detected the page having a broken link,
        // as the page has not been "published" yet.
        $this->isReportBroken($brokenRedirectorPagesReport, true, false);

        // Make sure the page is now "published".
        $page->writeToStage('Live');

        // ASSERT that the "draft" report has detected the page having a broken link.
        // ASSERT that the "published" report has detected the page having a broken link.
        $this->isReportBroken($brokenRedirectorPagesReport, true, true);

        // Correct the "draft" broken link.
        $contentPage = new SiteTree();
        $contentPage->Content = 'This is some content.';
        $contentPage->writeToStage('Stage');
        $contentPage->writeToStage('Live');
        $page->LinkToID = $contentPage->ID;
        $page->writeToStage('Stage');

        // ASSERT that the "draft" report has NOT detected the page having a broken link.
        // ASSERT that the "published" report has detected the page having a broken link,
        // as the previous content remains "published".
        $this->isReportBroken($brokenRedirectorPagesReport, false, true);

        // Make sure the change has now been "published".
        $page->writeToStage('Live');

        // ASSERT that the "draft" report has NOT detected the page having a broken link.
        // ASSERT that the "published" report has NOT detected the page having a broken link.
        $this->isReportBroken($brokenRedirectorPagesReport, false, false);
    }
}
