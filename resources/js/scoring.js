export function roundScore(value, precision = 1) {
    if (value === null) return null;
    const multiplier = 10 ** precision;
    return Math.round((value + Number.EPSILON * Math.max(1, Math.abs(value))) * multiplier) / multiplier;
}

export function categoryForScore(score, scoring) {
    if (score === null) return scoring.unrated.label;
    const rounded = roundScore(score, scoring.score_precision);
    return scoring.categories.find((category) => rounded >= category.minimum)?.label ?? scoring.unrated.label;
}

export function calculateScores(questionnaire, answers) {
    const values = new Map(answers.map((answer) => [Number(answer.question_id), Number(answer.value)]));
    const factorScores = {};
    const fullScores = [];
    let answeredCount = 0;
    let totalQuestions = 0;
    for (const factor of questionnaire.factors) {
        if (factor.scale_max <= factor.scale_min) throw new RangeError('Skala faktor tidak valid.');
        const selected = factor.questions.filter((question) => values.has(question.id)).map((question) => values.get(question.id));
        answeredCount += selected.length;
        totalQuestions += factor.questions.length;
        const score = selected.length === 0 ? null : (selected.reduce((sum, value) => sum + value, 0) - selected.length * factor.scale_min) * 100 / (selected.length * (factor.scale_max - factor.scale_min));
        factorScores[factor.code] = roundScore(score, questionnaire.scoring.factor_precision);
        if (score !== null) fullScores.push(score);
    }
    const average = fullScores.length === 0 ? null : roundScore(fullScores.reduce((sum, score) => sum + score, 0) / fullScores.length, questionnaire.scoring.score_precision);
    return { factor_scores: factorScores, average_score: average, category: categoryForScore(average, questionnaire.scoring), answered_count: answeredCount, total_questions: totalQuestions };
}
