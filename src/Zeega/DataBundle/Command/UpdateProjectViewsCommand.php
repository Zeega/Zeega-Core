<?php

/*
* This file is part of Zeega.
*
* (c) Zeega <info@zeega.org>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Zeega\DataBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

use Zeega\CoreBundle\Helpers\ResponseHelper;
use Zeega\DataBundle\Entity\Item;

/**
 * Updates a task status
 *
 */
class UpdateProjectViewsCommand extends ContainerAwareCommand
{
    /**
     * @see Command
     */       
    protected function configure()
    {
        $this->setName('zeega:items:update-views')
             ->setDescription('Updates item views counts with data from Redis')
             ->setHelp("Help");
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $redis = $this->getContainer()->get('snc_redis.default');
    
        $viewKeys = $redis->keys('views:*');
        $projectsToUpdateIds = array();
        $projectsToUpdateValues = array();

        foreach($viewKeys as $viewKey) {
            $projectId = str_replace("views:","",$viewKey);
            $viewKeyForCopy = str_replace("views:","views-copy:",$viewKey);
            $redis->rename($viewKey, $viewKeyForCopy);                
            $views = $redis->get($viewKeyForCopy);
            
            if (is_numeric($projectId)) {
                $projectId = (int)$projectId;
            }

            array_push($projectsToUpdateIds, $projectId);
            $projectsToUpdateValues[$projectId] = $views;
        }
        
        if ( count($projectsToUpdateIds > 0) ) {
            $dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
            $projects = $dm->createQueryBuilder('Zeega\DataBundle\Document\Project')
                ->field('id')->in($projectsToUpdateIds)
                ->getQuery()->execute();
            
            foreach($projects as $project) {
                $views = $project->getViews();
                $projectId = (string)$project->getId();
                $project->setViews($views + $projectsToUpdateValues[$projectId]);
                $dm->persist($project);
                $redis->del("views-copy:$projectId");
                unset($projectsToUpdateIds[$projectId]);

                $output->writeln("Updated $projectId");
            }

            // temp method to update projects that use the old ids
            if( count($projectsToUpdateIds) > 0 ) {
                $projects = $dm->createQueryBuilder('Zeega\DataBundle\Document\Project')
                    ->field('rdbmsIdPublished')->in($projectsToUpdateIds)
                    ->getQuery()->execute();
                
                foreach($projects as $project) {
                    $views = $project->getViews();
                    $projectId = (string)$project->getRdbmsIdPublished();
                    $project->setViews($views + $projectsToUpdateValues[$projectId]);
                    $dm->persist($project);
                    $redis->del("views-copy:$projectId");
                
                    $output->writeln("Updated $projectId");
                }                    
            }

            $dm->flush();
        }
    }
}
