<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

namespace Gibbon\Module\Reports;

use Gibbon\Services\BackgroundProcess;
use Gibbon\Comms\NotificationSender;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Domain\Students\StudentGateway;
use Gibbon\Module\Reports\ArchiveFile;
use Gibbon\Module\Reports\Domain\ReportGateway;
use Gibbon\Module\Reports\Domain\ReportArchiveEntryGateway;
use Gibbon\Module\Reports\Domain\ReportArchiveGateway;
use League\Container\ContainerAwareInterface;
use League\Container\ContainerAwareTrait;

/**
 * GenerateReportProcess
 *
 * @version v19
 * @since   v19
 */
class GenerateReportProcess extends BackgroundProcess implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    protected $absolutePath;

    public function __construct(SettingGateway $settingGateway)
    {
        $this->absolutePath = $settingGateway->getSettingByScope('System', 'absolutePath');
    }

    public function runReportBatch($gibbonReportID, $contexts = [], $status = 'Draft', $gibbonPersonID = null)
    {
        $timeStart = time();
        $report = $this->container->get(ReportGateway::class)->getByID($gibbonReportID);

        if (empty($gibbonReportID) || empty($report)) {
            return false;
        }

        $reportArchiveEntryGateway = $this->container->get(ReportArchiveEntryGateway::class);
        $studentGateway = $this->container->get(StudentGateway::class);

        $reportBuilder = $this->container->get(ReportBuilder::class);
        $archive = $this->container->get(ReportArchiveGateway::class)->getByID($report['gibbonReportArchiveID']);
        $archiveFile = $this->container->get(ArchiveFile::class);

        $template = $reportBuilder->buildTemplate($report['gibbonReportTemplateID'], $status == 'Draft');

        foreach ($contexts as $contextData) {
            $reports = $reportBuilder->buildReportBatch($template, $report, $contextData);

            $renderer = new ReportRenderer($template, $this->container->get('twig'));
            $renderer->setMode(ReportRenderer::OUTPUT_CONTINUOUS | ReportRenderer::OUTPUT_TWO_SIDED);

            // Render the Report: Batch
            $path = $archiveFile->getBatchFilePath($gibbonReportID, $contextData);
            $renderer->renderToPDF($reports, $this->absolutePath.$archive['path'].'/'.$path);

            // Update the Archive: Batch
            $reportArchiveEntryGateway->insertAndUpdate([
                'reportIdentifier'      => $report['name'],
                'gibbonReportID'        => $gibbonReportID,
                'gibbonReportArchiveID' => $report['gibbonReportArchiveID'],
                'gibbonSchoolYearID'    => $report['gibbonSchoolYearID'],
                'gibbonYearGroupID'     => $contextData,
                'type'                  => 'Batch',
                'status'                => $status,
                'filePath'              => $path,
            ], ['status' => $status, 'timestampModified' => date('Y-m-d H:i:s')]);

            // Create reports for each student
            foreach ($reports as $studentReport) {
                $identifier = $studentReport->getID('gibbonStudentEnrolmentID');

                if ($student = $studentGateway->getByID($identifier)) {
                    // Render the Report: Single
                    $path = $archiveFile->getSingleFilePath($gibbonReportID, $student['gibbonYearGroupID'], $identifier);
                    $renderer->renderToPDF([$studentReport], $this->absolutePath.$archive['path'].'/'.$path);

                    // Update the Archive: Single
                    $reportArchiveEntryGateway->insertAndUpdate([
                        'reportIdentifier'      => $report['name'],
                        'gibbonReportID'        => $gibbonReportID,
                        'gibbonReportArchiveID' => $report['gibbonReportArchiveID'],
                        'gibbonSchoolYearID'    => $student['gibbonSchoolYearID'],
                        'gibbonYearGroupID'     => $student['gibbonYearGroupID'],
                        'gibbonRollGroupID'     => $student['gibbonRollGroupID'],
                        'gibbonPersonID'        => $student['gibbonPersonID'],
                        'type'                  => 'Single',
                        'status'                => $status,
                        'filePath'              => $path,
                    ], ['status' => $status, 'timestampModified' => date('Y-m-d H:i:s'), 'filePath' => $path]);
                }
            }
        }

        // Notify the person who created this report
        if (!empty($gibbonPersonID)) {
            $timeEnd = time();
            $actionText = __('A Report Card Generation CLI script has run.').'<br/><br/>';
            $actionText .= 'Process time: ' . gmdate("H:i:s", ($timeEnd - $timeStart) ).'<br/>';
            $actionText .= 'Process finished on '.date(DATE_RFC2822);
            $actionLink = '/index.php?q=/modules/Reports/reports_generate_batch.php&gibbonReportID='.$gibbonReportID;

            $notificationSender = $this->container->get(NotificationSender::class);
            $notificationSender->addNotification($gibbonPersonID, $actionText, 'Reports', $actionLink);
            $notificationSender->sendNotifications();
        }

        return $contextData;
    }
}
