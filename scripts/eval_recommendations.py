"""
Lightweight offline evaluation helper for recommendation lists.

Usage:
    python scripts/eval_recommendations.py \
        --recommendations data/recs.json \
        --groundtruth data/groundtruth.csv \
        --k 5

Input formats:
    - recommendations JSON: list of objects
      [{ "user_id": "...", "items": [{"tour_id": "...", "score": 0.8}, ...]}]
    - groundtruth CSV: user_id,tour_id (one row per relevant item)

Metrics reported: Precision@K, Recall@K, NDCG@K.
This script is standalone (no external deps).
"""
import argparse
import csv
import json
import math
from collections import defaultdict
from typing import Dict, List, Set


def load_recommendations(path: str) -> Dict[str, List[str]]:
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)
    recs = {}
    for row in data:
        items = row.get("items") or row.get("recommendations") or []
        recs[str(row.get("user_id"))] = [str(it.get("tour_id")) for it in items if it.get("tour_id")]
    return recs


def load_groundtruth(path: str) -> Dict[str, Set[str]]:
    truth = defaultdict(set)
    with open(path, newline="", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for r in reader:
            truth[str(r["user_id"])].add(str(r["tour_id"]))
    return truth


def dcg(recommended: List[str], relevant: Set[str], k: int) -> float:
    score = 0.0
    for i, item in enumerate(recommended[:k], start=1):
        if item in relevant:
            score += 1 / math.log2(i + 1)
    return score


def ndcg_at_k(recommended: List[str], relevant: Set[str], k: int) -> float:
    ideal = dcg(list(relevant), relevant, k)
    if ideal == 0:
        return 0.0
    return dcg(recommended, relevant, k) / ideal


def evaluate(recs: Dict[str, List[str]], truth: Dict[str, Set[str]], k: int):
    precisions = []
    recalls = []
    ndcgs = []
    f1s = []

    users = set(recs.keys()) | set(truth.keys())
    for user in users:
        recommended = recs.get(user, [])[:k]
        relevant = truth.get(user, set())
        if not recommended and not relevant:
            continue

        hits = len([item for item in recommended if item in relevant])
        denom_precision = max(1, min(k, len(recommended)))
        denom_recall = max(1, len(relevant))

        precisions.append(hits / denom_precision)
        recalls.append(hits / denom_recall)
        ndcgs.append(ndcg_at_k(recommended, relevant, k))
        p = precisions[-1]
        r = recalls[-1]
        f1 = 0.0 if (p + r) == 0 else 2 * p * r / (p + r)
        f1s.append(f1)

    mean = lambda arr: sum(arr) / len(arr) if arr else 0.0
    return {
        "precision_at_k": round(mean(precisions), 4),
        "recall_at_k": round(mean(recalls), 4),
        "ndcg_at_k": round(mean(ndcgs), 4),
        "f1_at_k": round(mean(f1s), 4),
        "users_evaluated": len(precisions),
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--recommendations", required=True)
    parser.add_argument("--groundtruth", required=True)
    parser.add_argument("--k", type=int, default=5)
    args = parser.parse_args()

    recs = load_recommendations(args.recommendations)
    truth = load_groundtruth(args.groundtruth)
    metrics = evaluate(recs, truth, args.k)
    print(json.dumps(metrics, indent=2))


if __name__ == "__main__":
    main()
