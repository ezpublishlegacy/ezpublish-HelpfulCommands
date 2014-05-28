<?php

namespace Gaz\MiscBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputOption;

/**
 * Class PublishNewDraftCommand
 *
 * Takes a list of ContentId's and republishes them without make any changes.
 * This is useful after you have broken something and don't want
 * to fix it properly.
 *
 * Can be used as a wrapper for when you actually have some changes to make.
 *
 * @package Gaz\MiscBundle\Command
 * @author Gareth Arnott
 */
class PublishNewDraftCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setName( 'migration:publish_new_draft' )->setDefinition(
            array(
                new InputArgument( 'contentIds' , InputArgument::REQUIRED, 'the content to be updated' ),
            )
        );
    }

    protected function execute( InputInterface $input, OutputInterface $output )
    {
        /** @var $repository \eZ\Publish\API\Repository\Repository */
        $repository = $this->getContainer()->get( 'ezpublish.api.repository' );
        $contentService = $repository->getContentService();

        $repository->setCurrentUser( $repository->getUserService()->loadUser( 14 ) );

        $contentIds = $input->getArgument( 'contentIds' );
        $contentIds = explode( ',', $contentIds );
        $contentIds = array_filter( $contentIds );

        foreach( $contentIds as $contentId ) {
            try {
                // create a content draft from the current published version
                $contentInfo = $contentService->loadContentInfo($contentId);
                $contentDraft = $contentService->createContentDraft( $contentInfo );
                $contentUpdateStruct = $contentService->newContentUpdateStruct();

                /**
                 * @warning Bug in ezpublish.
                 * If the object has a file attribute, this re-publish
                 * will silently delete the original file, instead
                 * of copying and re-saving. :|
                 *
                 * @see https://jira.ez.no/browse/EZP-22808
                 *
                 */

                $contentDraft = $contentService->updateContent( $contentDraft->versionInfo, $contentUpdateStruct );
                $content = $contentService->publishVersion( $contentDraft->versionInfo );
                $output->write( $contentId );
                $output->writeln(" - content updated.");
            }
            //  if the content with the given id does not exist
            catch (\eZ\Publish\API\Repository\Exceptions\NotFoundException $e) {
                $output->writeln($e->getMessage());
            }
            //  if the user is not allowed to update this version
            catch (\eZ\Publish\API\Repository\Exceptions\UnauthorizedException $e) {
                $output->writeln($e->getMessage());
            }
            // if the version is not a draft
            catch (\eZ\Publish\API\Repository\Exceptions\BadStateException $e) {
                $output->writeln($e->getMessage());
            }
            // if a field in the $contentUpdateStruct is not valid
            catch (\eZ\Publish\API\Repository\Exceptions\ContentFieldValidationException $e) {
                $output->writeln($e->getMessage());
            }
            // if a required field is set to an empty value
            catch (\eZ\Publish\API\Repository\Exceptions\ContentValidationException $e) {
                $output->writeln($e->getMessage());
            }
            // if a field value is not accepted by the field type
            catch (\eZ\Publish\API\Repository\Exceptions\InvalidArgumentException $e) {
                $output->writeln($e->getMessage());
            }

        }
    }
}
