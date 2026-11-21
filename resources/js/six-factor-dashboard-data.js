import { sixFactors } from './six-factor-draft.js';

export const draftProgress = (scores) => Object.keys(sixFactors).filter((key) => scores[key] != null).length;
