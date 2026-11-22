<?php

namespace App\Chat\Model;

/**
 * What the worker pushes to the page of a conversation, on its Mercure topic: each one is a Turbo Stream, rendered
 * by templates/chat/stream/<value>.stream.html.twig.
 */
enum ChatEvent: string
{
    case Start = 'start';
    // A new bubble, then the same bubble with more of the answer
    case AnswerStarted = 'answer_started';
    case AnswerContinued = 'answer_continued';
    // In demo mode only, see App\Chat\ToolCallDisplay
    case ToolCall = 'tool_call';
    // Whatever the mode, see App\Chat\ToolCards
    case DraftCard = 'draft_card';
    case Choices = 'choices';
    case PlantingCard = 'planting_card';
    case SpaceCard = 'space_card';
    case Done = 'done';
    case Error = 'error';
    // The page of a planting shows its care sheet draft, see App\Garden\DraftCareSheetHandler
    case CareSheetDrafted = 'care_sheet_drafted';
    // The page of a planting shows its failure, with its Retry button, see App\Garden\DraftCareSheetHandler
    case Refresh = 'refresh';
}
